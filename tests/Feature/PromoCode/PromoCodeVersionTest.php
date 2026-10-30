<?php

namespace Tests\Feature\PromoCode;

use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCodeVersionTest extends TestCase
{
    use RefreshDatabase;

    private PromoCodeService $service;

    private Platform $platform;

    private Funnel $webinar;

    private Funnel $tryout;

    private PromoSource $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PromoCodeService::class);
        $this->platform = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->webinar = Funnel::factory()->create(['name' => 'Webinar']);
        $this->tryout = Funnel::factory()->create(['name' => 'Tryout Gratis']);
        $this->instagram = PromoSource::factory()->create(['name' => 'Instagram']);
    }

    private function transaction(string $paidDate, string $paidTime, array $codes = ['CPNS26']): Transaction
    {
        return Transaction::factory()->for($this->platform)->create([
            'paid_date' => $paidDate,
            'paid_time' => $paidTime,
            'promo_code' => $codes,
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function snapshot(Transaction $transaction): array
    {
        $usage = $transaction->promoCodeUsages()->first();

        return [$usage->funnel_name_snapshot, $usage->source_name_snapshot];
    }

    /**
     * Webinar + Instagram from 1 Sep 2026 08:00, Tryout Gratis without source from 15 Sep 2026 09:30.
     */
    private function codeEditedOnSeptember15(): PromoCode
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $promoCode = $this->service->create($this->platform->id, 'CPNS26', $this->webinar->id, $this->instagram->id);

        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 9, 30));
        $this->service->updateMetadata($promoCode, $this->tryout->id, null);

        return $promoCode;
    }

    public function test_creating_a_code_opens_a_version_from_the_moment_it_is_saved(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8, 15, 42));

        $version = $this->service->create($this->platform->id, 'cpns26', $this->webinar->id, $this->instagram->id)
            ->versions()->sole();

        $this->assertSame('CPNS26', $version->code);
        $this->assertSame('2026-09-01 08:15:42', $version->effective_from->toDateTimeString());
        $this->assertNull($version->effective_until);
        $this->assertSame(['Webinar', 'Instagram'], [$version->funnel_name_snapshot, $version->source_name_snapshot]);
    }

    public function test_editing_metadata_closes_the_old_version_and_opens_a_new_one(): void
    {
        $promoCode = $this->codeEditedOnSeptember15();
        $versions = $promoCode->versions()->orderBy('effective_from')->get();

        $this->assertCount(2, $versions);
        $this->assertSame('2026-09-15 09:30:00', $versions[0]->effective_until->toDateTimeString());
        $this->assertSame('2026-09-15 09:30:00', $versions[1]->effective_from->toDateTimeString());
        $this->assertNull($versions[1]->effective_until);
        $this->assertSame(['Tryout Gratis', null], [$versions[1]->funnel_name_snapshot, $versions[1]->source_name_snapshot]);
        $this->assertSame($this->tryout->id, $promoCode->fresh()->funnel_id);
    }

    public function test_saving_the_same_metadata_creates_no_version(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $promoCode = $this->service->create($this->platform->id, 'CPNS26', $this->webinar->id);

        $this->travelTo(CarbonImmutable::create(2026, 9, 2, 8));
        $this->service->updateMetadata($promoCode, $this->webinar->id, null);

        $this->assertSame(1, $promoCode->versions()->count());
    }

    public function test_two_edits_in_the_same_second_keep_one_version(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $promoCode = $this->service->create($this->platform->id, 'CPNS26', $this->webinar->id);

        $this->travelTo(CarbonImmutable::create(2026, 9, 2, 8));
        $this->service->updateMetadata($promoCode, $this->tryout->id, null);
        $this->service->updateMetadata($promoCode, null, $this->instagram->id);

        $this->assertSame(2, $promoCode->versions()->count());
        $this->assertSame([null, 'Instagram'], [
            $promoCode->currentVersion()->first()->funnel_name_snapshot,
            $promoCode->currentVersion()->first()->source_name_snapshot,
        ]);
    }

    public function test_initial_classification_uses_current_master_regardless_of_payment_time(): void
    {
        $this->codeEditedOnSeptember15();

        $this->assertSame(['Tryout Gratis', null], $this->snapshot($this->transaction('2026-09-15', '09:29:59')));
        $this->assertSame(['Tryout Gratis', null], $this->snapshot($this->transaction('2026-09-15', '09:30:00')));
    }

    public function test_classified_usages_keep_their_snapshot_after_the_master_is_edited(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $promoCode = $this->service->create($this->platform->id, 'CPNS26', $this->webinar->id, $this->instagram->id);
        $transaction = $this->transaction('2026-09-10', '10:00:00');

        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 9, 30));
        $this->service->updateMetadata($promoCode, $this->tryout->id, null);

        // A resync of the same transaction, even with a corrected payment time, keeps the classification.
        $transaction->update(['paid_time' => '23:00:00']);

        $this->assertSame(['Webinar', 'Instagram'], $this->snapshot($transaction));
    }

    public function test_late_transactions_paid_before_registration_use_current_master(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $promoCode = $this->service->create($this->platform->id, 'CPNS26', $this->webinar->id);

        $usage = $this->transaction('2026-08-20', '10:00:00')->promoCodeUsages()->first();

        $this->assertSame($promoCode->id, $usage->promo_code_id);
        $this->assertSame($this->webinar->id, $usage->funnel_id_snapshot);
        $this->assertSame('Webinar', $usage->funnel_name_snapshot);
        $this->assertTrue($usage->isClassified());
    }

    public function test_registering_a_code_from_the_alert_links_unmapped_usages_and_keeps_classified_snapshots(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 20, 8));
        $unmapped = $this->transaction('2026-09-10', '10:00:00', ['newcode']);
        $otherPlatform = Transaction::factory()->for(Platform::factory())->create(['paid_date' => '2026-09-10', 'promo_code' => ['NEWCODE']]);

        // Legacy usage that was already classified without a master must not be overwritten.
        $classified = $this->transaction('2026-09-11', '10:00:00', ['NEWCODE']);
        $classified->promoCodeUsages()->update(['funnel_name_snapshot' => 'Funnel Lama']);

        $promoCode = $this->service->registerFromAlert($this->platform->id, 'NEWCODE', $this->webinar->id, $this->instagram->id);

        $this->assertSame([$promoCode->id, 'Webinar', 'Instagram'], [
            $unmapped->promoCodeUsages()->first()->promo_code_id,
            ...$this->snapshot($unmapped),
        ]);
        $this->assertNull($otherPlatform->promoCodeUsages()->first()->promo_code_id);

        // The classified usage is linked to the master, but its snapshot is not overwritten.
        $this->assertSame($promoCode->id, $classified->promoCodeUsages()->first()->promo_code_id);
        $this->assertSame(['Funnel Lama', null], $this->snapshot($classified));
    }

    public function test_usages_without_metadata_stay_unclassified_for_the_tanpa_funnel_group(): void
    {
        $this->transaction('2026-09-10', '10:00:00', ['UNKNOWN']);

        $this->assertSame([null, null], $this->snapshot(Transaction::first()));
        $this->assertSame(1, TransactionPromoCode::unmapped()->unclassified()->count());
    }
}
