<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PromoCodeSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Account $employee;

    private Platform $cerebrum;

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
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);

        fake()->seed(2026);
        $this->seed([PromoCodeSeeder::class, TransactionSeeder::class]);
    }

    /**
     * @return list<string>
     */
    private function newCodes(): array
    {
        return array_values(array_unique(array_map(PromoCode::normalizeCode(...), TransactionSeeder::NEW_CODES)));
    }

    public function test_every_platform_gets_its_admin_program_and_reseller_codes_with_a_metadata_version(): void
    {
        $codes = PromoCode::where('platform_id', $this->cerebrum->id)->with(['funnel', 'source'])->get()->keyBy('code');

        $this->assertTrue($codes->has('ADMINCEREBRUM'));
        $this->assertSame(['Artikel', null], [$codes['ADMINCEREBRUM']->funnel?->name, $codes['ADMINCEREBRUM']->source?->name]);
        $this->assertTrue($codes->has('BIMBELSNBT') && $codes->has('BIMBELTKA') && $codes->has('RES'));
        $this->assertSame([null, null], [$codes['RES']->funnel_id, $codes['RES']->source_id]);
        $this->assertSame([], array_intersect($codes->keys()->all(), $this->newCodes()));
        $codes->each(fn (PromoCode $code) => $this->assertSame(1, $code->versions()->count()));
    }

    public function test_registered_codes_of_the_transactions_are_linked_to_the_master_of_their_platform(): void
    {
        $this->assertGreaterThan(0, Transaction::whereNotNull('promo_code')->count());

        $linked = TransactionPromoCode::whereNotNull('promo_code_id')->with('promoCode')->get();

        $this->assertGreaterThan(0, $linked->count());
        $linked->each(function (TransactionPromoCode $usage) {
            $this->assertSame((int) $usage->platform_id_snapshot, $usage->promoCode->platform_id);
            $this->assertSame($usage->code_snapshot, $usage->promoCode->code);
        });

        // Snapshots come from the master, and Markaz spellings are kept on the usage.
        $this->assertTrue(TransactionPromoCode::where('code_snapshot', 'ADMINCEREBRUM')->where('funnel_name_snapshot', 'Artikel')->exists());
        $this->assertTrue(TransactionPromoCode::whereColumn('raw_code', '!=', 'code_snapshot')->whereNotNull('promo_code_id')->exists());
        $this->assertTrue(TransactionPromoCode::where('code_position', 1)->where('code_snapshot', 'RES')->exists());
    }

    public function test_only_new_codes_of_the_last_days_are_in_the_unregistered_alert(): void
    {
        $this->assertSame([], TransactionPromoCode::whereNull('promo_code_id')->pluck('code_snapshot')->diff($this->newCodes())->values()->all());

        $alert = $this->actingAs($this->employee)->getJson('/api/promo-codes/unregistered')->assertOk()->json();
        $since = today()->subDays(TransactionSeeder::NEW_CODE_DAYS)->toDateString();

        $this->assertGreaterThan(0, $alert['meta']['total']);
        foreach ($alert['data'] as $row) {
            $this->assertContains($row['code'], $this->newCodes());
            $this->assertGreaterThanOrEqual($since, $row['first_used_on']);
        }
    }

    public function test_registering_a_new_code_from_the_alert_links_its_seeded_usages(): void
    {
        $row = $this->actingAs($this->employee)->getJson('/api/promo-codes/unregistered')->json('data.0');

        $promoCode = app(PromoCodeService::class)->registerFromAlert($row['platform']['id'], $row['code']);

        $this->assertSame($row['usages_count'], TransactionPromoCode::where('promo_code_id', $promoCode->id)->count());
        $this->assertNotContains($row['code'], $this->actingAs($this->employee)->getJson('/api/promo-codes/unregistered')->json('data.*.code'));
    }

    public function test_three_code_transactions_are_anomalies_without_usages(): void
    {
        $anomalies = Transaction::where('promo_code_anomaly', true)->get();

        $this->assertGreaterThan(0, $anomalies->count());
        $anomalies->each(function (Transaction $transaction) {
            $this->assertCount(3, $transaction->promo_code);
            $this->assertSame(0, $transaction->promoCodeUsages()->count());
        });
    }

    public function test_running_the_seeders_again_adds_nothing(): void
    {
        $counts = [PromoCode::count(), Transaction::count(), TransactionPromoCode::count()];

        $this->seed([PromoCodeSeeder::class, TransactionSeeder::class]);

        $this->assertSame($counts, [PromoCode::count(), Transaction::count(), TransactionPromoCode::count()]);
    }
}
