<?php

namespace Tests\Feature\PromoCode;

use App\Models\Funnel;
use App\Models\Platform;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use App\Services\PromoCodeUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCodeUsageNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platformA;

    private Platform $platformB;

    private string $anomalyLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformA = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->platformB = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);

        $this->anomalyLog = storage_path('logs/promo-code-anomalies-test.log');
        @unlink($this->anomalyLog);
        config(['logging.channels.promo_code_anomalies.path' => $this->anomalyLog]);
    }

    protected function tearDown(): void
    {
        @unlink($this->anomalyLog);

        parent::tearDown();
    }

    private function transaction(mixed $promoCode, ?Platform $platform = null, array $attributes = []): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->platformA)->create([
            'trx_id' => 'jadiasn-'.fake()->unique()->numberBetween(100000, 999999),
            'paid_date' => '2026-09-10',
            'paid_time' => '10:00:00',
            'promo_code' => $promoCode,
            ...$attributes,
        ]);
    }

    /**
     * @return list<array{0: int, 1: string, 2: string, 3: int|null}>
     */
    private function usages(Transaction $transaction): array
    {
        return $transaction->promoCodeUsages()->get()
            ->map(fn (TransactionPromoCode $usage) => [$usage->code_position, $usage->raw_code, $usage->code_snapshot, $usage->promo_code_id])
            ->all();
    }

    public function test_transactions_without_codes_have_no_usages(): void
    {
        $this->transaction(null);
        $this->transaction([]);
        $this->transaction('');
        $this->transaction([null, '  ']);

        $this->assertSame(0, TransactionPromoCode::count());
    }

    public function test_codes_are_stored_in_markaz_order_with_their_raw_value(): void
    {
        $transaction = $this->transaction(['Reseller01 ', 'CPNS26']);

        $this->assertSame([
            [1, 'Reseller01 ', 'RESELLER01', null],
            [2, 'CPNS26', 'CPNS26', null],
        ], $this->usages($transaction));
        $this->assertSame(['Reseller01 ', 'CPNS26'], $transaction->fresh()->promo_code);
    }

    public function test_a_single_code_string_is_the_primary_code(): void
    {
        $this->assertSame([[1, 'CPNS26', 'CPNS26', null]], $this->usages($this->transaction('CPNS26')));
    }

    public function test_an_empty_entry_keeps_the_position_of_the_next_code(): void
    {
        $this->assertSame([[2, 'RESELLER01', 'RESELLER01', null]], $this->usages($this->transaction(['', 'RESELLER01'])));
    }

    public function test_master_is_matched_by_platform_and_normalized_code(): void
    {
        $promoCode = app(PromoCodeService::class)->create($this->platformA->id, 'CPNS26');

        $samePlatform = $this->transaction([' cpns26 ']);
        $otherPlatform = $this->transaction(['CPNS26'], $this->platformB);

        $this->assertSame([[1, ' cpns26 ', 'CPNS26', $promoCode->id]], $this->usages($samePlatform));
        $this->assertSame([[1, 'CPNS26', 'CPNS26', null]], $this->usages($otherPlatform));
    }

    public function test_unknown_codes_are_kept_for_analysis_and_alerts(): void
    {
        $this->transaction(['NEWCODE']);
        $this->transaction(['NEWCODE']);

        $this->assertSame(2, TransactionPromoCode::unmapped()->where('code_snapshot', 'NEWCODE')->count());
    }

    public function test_processing_again_never_duplicates_usages(): void
    {
        $transaction = $this->transaction(['CPNS26', 'RESELLER01']);
        $service = app(PromoCodeUsageService::class);

        $service->normalize($transaction);
        $service->normalize($transaction->fresh());
        $transaction->update(['nominal' => 99000]);

        $this->assertSame(2, TransactionPromoCode::count());
    }

    public function test_changed_codes_from_markaz_update_the_usages(): void
    {
        $transaction = $this->transaction(['CPNS26', 'RESELLER01']);

        $transaction->update(['promo_code' => ['CPNS27']]);
        $this->assertSame([[1, 'CPNS27', 'CPNS27', null]], $this->usages($transaction));

        $transaction->update(['promo_code' => null]);
        $this->assertSame([], $this->usages($transaction));
    }

    public function test_more_than_two_codes_is_recorded_as_an_anomaly_without_usages(): void
    {
        $transaction = $this->transaction(['CPNS26', 'RESELLER01', 'EXTRA']);

        $this->assertSame([], $this->usages($transaction));
        $this->assertSame(['CPNS26', 'RESELLER01', 'EXTRA'], $transaction->fresh()->promo_code);
        $this->assertFalse(app(PromoCodeUsageService::class)->normalize($transaction));

        $log = file_get_contents($this->anomalyLog);
        $this->assertStringContainsString('Transaksi memiliki lebih dari dua kode promo.', $log);
        $this->assertStringContainsString($transaction->trx_id, $log);
    }

    public function test_an_unreadable_value_is_recorded_as_an_anomaly(): void
    {
        $transaction = $this->transaction(['code' => 'CPNS26']);

        $this->assertSame([], $this->usages($transaction));
        $this->assertStringContainsString('Format promo_code tidak dikenali.', file_get_contents($this->anomalyLog));
    }

    public function test_backfill_command_normalizes_existing_transactions_once(): void
    {
        // Rows inserted in bulk, like the seeder or an import, skip the model observer.
        Transaction::insert([
            [
                'platform_id' => $this->platformA->id,
                'markaz_user_id' => 1,
                'trx_id' => 'jadiasn-1',
                'paid_date' => '2026-09-10',
                'nominal' => 100000,
                'promo_code' => json_encode(['CPNS26', 'RESELLER01']),
            ],
            [
                'platform_id' => $this->platformA->id,
                'markaz_user_id' => 2,
                'trx_id' => 'jadiasn-2',
                'paid_date' => '2026-09-10',
                'nominal' => 100000,
                'promo_code' => json_encode(['A', 'B', 'C']),
            ],
        ]);

        $this->artisan('promo-codes:normalize')
            ->expectsOutput('2 transaksi diproses, 1 anomali dicatat di storage/logs/promo-code-anomalies.log.')
            ->assertSuccessful();
        $this->artisan('promo-codes:normalize')->assertSuccessful();

        $this->assertSame(2, TransactionPromoCode::count());
    }

    public function test_usage_gets_the_metadata_valid_on_the_transaction_date(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $funnel = Funnel::factory()->create(['name' => 'Webinar CPNS']);
        app(PromoCodeService::class)->create($this->platformA->id, 'CPNS26', $funnel->id);

        $usage = $this->transaction(['CPNS26'])->promoCodeUsages()->first();

        $this->assertSame($funnel->id, $usage->funnel_id_snapshot);
        $this->assertSame('Webinar CPNS', $usage->funnel_name_snapshot);
        $this->assertNull($usage->source_id_snapshot);
    }
}
