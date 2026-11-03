<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoSource;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnregisteredPromoCodeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-codes/unregistered';

    private Account $employee;

    private Platform $jadiasn;

    private Platform $cerebrum;

    private Funnel $aplikasi;

    private PromoSource $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 9, 25, 8));
        $this->seed(DatabaseSeeder::class);

        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Sales Marketing',
            'username' => 'sales',
            'password' => 'Employee123!',
        ]);
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
        $this->aplikasi = Funnel::where('name', 'Aplikasi')->first();
        $this->instagram = PromoSource::where('name', 'Instagram')->first();
    }

    /**
     * @param  list<string>  $codes
     */
    private function transaction(array $codes, string $paidDate = '2026-09-10', ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'paid_time' => '10:00:00',
            'promo_code' => $codes,
        ]);
    }

    private function alert(string $query = ''): array
    {
        return $this->actingAs($this->employee)->getJson(self::URL.$query)->assertOk()->json();
    }

    public function test_guest_cannot_view_the_alert(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_unregistered_codes_are_grouped_per_platform_and_normalized_code(): void
    {
        $this->transaction(['CPNS26'], '2026-09-05');
        $this->transaction([' cpns26 ', 'RESELLER01'], '2026-09-20');
        // The same code twice in one transaction counts as two usages of one transaction.
        $this->transaction(['CPNS26', 'cpns26'], '2026-09-12');
        $this->transaction(['CPNS26'], '2026-09-15', $this->cerebrum);

        $alert = $this->alert();

        $this->assertSame(3, $alert['meta']['total']);
        $this->assertSame([
            'code' => 'CPNS26',
            'raw_codes' => [' cpns26 ', 'CPNS26', 'cpns26'],
            'platform' => ['id' => $this->jadiasn->id, 'slug' => 'jadiasn', 'name' => 'JADIASN'],
            'usages_count' => 4,
            'transactions_count' => 3,
            'first_used_on' => '2026-09-05',
            'last_used_on' => '2026-09-20',
            'register' => ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id],
        ], $alert['data'][0]);

        // The same code on another platform is a separate unregistered code.
        $this->assertSame(['CEREBRUM', 1], [$alert['data'][1]['platform']['name'], $alert['data'][1]['usages_count']]);
        $this->assertSame(['RESELLER01', 1], [$alert['data'][2]['code'], $alert['data'][2]['usages_count']]);
    }

    public function test_unregistered_codes_stay_on_the_transaction_and_count_in_analysis(): void
    {
        $transaction = $this->transaction(['NEWCODE', 'RESELLER01']);

        $this->assertSame(['NEWCODE', 'RESELLER01'], $transaction->fresh()->promo_code);
        $this->assertSame([[1, 'NEWCODE'], [2, 'RESELLER01']], $transaction->promoCodeUsages()->get()
            ->map(fn (TransactionPromoCode $usage) => [$usage->code_position, $usage->raw_code])->all());
        $this->assertSame(2, TransactionPromoCode::unmapped()->count());
    }

    public function test_registered_codes_are_not_in_the_alert_even_when_never_used(): void
    {
        app(PromoCodeService::class)->create($this->jadiasn->id, 'UNUSED26');
        app(PromoCodeService::class)->register($this->jadiasn->id, 'USED26');
        $this->transaction(['USED26']);
        $this->transaction(['NEWCODE']);

        $this->assertSame(['NEWCODE'], array_column($this->alert()['data'], 'code'));
    }

    public function test_a_code_with_a_master_is_not_in_the_alert_even_if_some_usages_are_unlinked(): void
    {
        $this->transaction(['CPNS26']);
        // A master created without linking (not through the API) still makes the code registered.
        app(PromoCodeService::class)->create($this->jadiasn->id, 'CPNS26');

        $this->assertSame(0, $this->alert()['meta']['total']);
    }

    public function test_registering_from_the_alert_maps_history_and_removes_only_that_code(): void
    {
        $first = $this->transaction([' cpns26 ', 'RESELLER01'], '2026-09-10');
        $second = $this->transaction(['CPNS26'], '2026-09-12');
        $classified = $this->transaction(['CPNS26'], '2026-09-13');
        $classified->promoCodeUsages()->update(['funnel_name_snapshot' => 'Funnel Lama']);
        $this->transaction(['CPNS26'], '2026-09-14', $this->cerebrum);

        $register = collect($this->alert()['data'])->firstWhere('platform.id', $this->jadiasn->id)['register'];

        $promoCodeId = $this->postJson('/api/promo-codes', [
            ...$register,
            'funnel_id' => $this->aplikasi->id,
            'source_id' => $this->instagram->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.usages_count', 3)
            ->json('data.id');

        // Unmapped usages get the registered metadata; raw value and position stay as Markaz sent them.
        $usage = $first->promoCodeUsages()->where('code_position', 1)->first();
        $this->assertSame([$promoCodeId, ' cpns26 ', 1, 'Aplikasi', 'Instagram'], [
            $usage->promo_code_id, $usage->raw_code, $usage->code_position, $usage->funnel_name_snapshot, $usage->source_name_snapshot,
        ]);
        $this->assertSame('Aplikasi', $second->promoCodeUsages()->first()->funnel_name_snapshot);

        // An already classified usage is linked but keeps its snapshot.
        $this->assertSame([$promoCodeId, 'Funnel Lama', null], [
            $classified->promoCodeUsages()->first()->promo_code_id,
            $classified->promoCodeUsages()->first()->funnel_name_snapshot,
            $classified->promoCodeUsages()->first()->source_name_snapshot,
        ]);

        // CPNS26 on JADIASN leaves the alert; the reseller code and CPNS26 on CEREBRUM stay.
        $remaining = collect($this->alert()['data'])->map(fn (array $row) => $row['code'].'@'.$row['platform']['slug'])->sort()->values()->all();
        $this->assertSame(['CPNS26@cerebrum', 'RESELLER01@jadiasn'], $remaining);
    }

    public function test_registering_from_the_alert_follows_the_unique_rule(): void
    {
        $this->transaction(['CPNS26']);
        app(PromoCodeService::class)->create($this->jadiasn->id, 'CPNS26');

        $this->actingAs($this->employee)
            ->postJson('/api/promo-codes', ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode promo CPNS26 sudah terdaftar di platform JADIASN.']);
    }

    public function test_alert_supports_search_platform_filter_and_pagination(): void
    {
        $this->transaction(['CPNS26', 'BUMN26']);
        $this->transaction(['CPNS26']);
        $this->transaction(['POLRI26'], platform: $this->cerebrum);

        $this->assertSame(['CPNS26', 'BUMN26'], array_column($this->alert('?per_page=2')['data'], 'code'));
        $this->assertSame(['POLRI26'], array_column($this->alert('?per_page=2&page=2')['data'], 'code'));
        $this->assertSame(['CPNS26'], array_column($this->alert('?search=cpns')['data'], 'code'));
        $this->assertSame(['POLRI26'], array_column($this->alert('?search=cerebrum')['data'], 'code'));
        $this->assertSame(['POLRI26'], array_column($this->alert('?platform_id='.$this->cerebrum->id)['data'], 'code'));

        $this->getJson(self::URL.'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page' => 'Jumlah per halaman harus di antara 1 dan 100.']);
    }

    public function test_alert_is_empty_when_every_code_is_registered(): void
    {
        app(PromoCodeService::class)->register($this->jadiasn->id, 'CPNS26');
        $this->transaction(['CPNS26']);

        $this->assertSame(['data' => [], 'total' => 0], ['data' => $this->alert()['data'], 'total' => $this->alert()['meta']['total']]);
    }
}
