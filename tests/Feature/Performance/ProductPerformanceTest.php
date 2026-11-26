<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\PeriodResolverService;
use App\Services\ProductPerformanceService;
use App\Support\Period\PeriodSelection;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-11-BE-01: the Dashboard Performa Produk dataset.
 */
class ProductPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/products';

    /** On 11 Nov 2026, last_7_days is active 04 - 10 Nov. */
    private const NOW = '2026-11-11T14:30:00+07:00';

    private Account $manager;

    private Platform $appA;

    private Platform $appB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->appA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->appB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
        $this->coverage($this->appA);
        $this->coverage($this->appB);
    }

    public function test_ac01_same_product_name_on_two_platforms_stays_separate_and_follows_the_platform_filter(): void
    {
        $this->transaction($this->appA, 'Paket SKD', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 100000);
        $this->transaction($this->appB, 'Paket SKD', '2026-11-05 10:00:00', '2026-11-05 10:05:00', 50000);
        $this->transaction($this->appA, ' paket  skd ', '2026-11-06 10:00:00', null, 0, MarkazTransactionStatus::Pending);

        $table = $this->table(['app-a', 'app-b']);

        $this->assertSame(
            ['product', 'total_transactions', 'success_purchases', 'pending_purchases', 'unsuccessful_purchases', 'revenue', 'transaction_contribution', 'revenue_contribution'],
            array_column($table['columns'], 'key'),
        );
        $this->assertSame(['app-a:paket skd', 'app-b:paket skd'], array_column($table['rows'], 'key'));
        $this->assertSame(['Paket SKD', 'Paket SKD'], array_column($table['rows'], 'product'));
        $this->assertSame(['App A', 'App B'], array_column(array_column($table['rows'], 'platform'), 'name'));
        $this->assertSame(2, $table['rows'][0]['total_transactions']);
        $this->assertSame(1, $table['rows'][1]['total_transactions']);

        $onlyB = $this->table(['app-b']);

        $this->assertSame(['app-b:paket skd'], array_column($onlyB['rows'], 'key'));
        $this->assertSame(100.0, (float) $onlyB['rows'][0]['transaction_contribution']);
    }

    public function test_ac02_search_sort_and_pages_keep_the_main_filter_divisor(): void
    {
        // A: 80 of 100 Success purchases, Rp8 juta of Rp20 juta Omzet.
        for ($i = 0; $i < 80; $i++) {
            $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 100000);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->transaction($this->appA, 'Produk B', '2026-11-06 09:00:00', '2026-11-06 09:05:00', 600000);
        }

        $full = $this->table(['app-a']);
        $a = $this->rowsByProduct($full)['Produk A'];

        $this->assertSame(100, $full['totals']['success_purchases']);
        $this->assertEquals(20000000, $full['totals']['revenue']);
        $this->assertSame(80.0, (float) $a['transaction_contribution']);
        $this->assertSame(40.0, (float) $a['revenue_contribution']);

        $searched = $this->table(['app-a'], ['search' => 'produk a']);
        $this->assertSame(['Produk A'], array_column($searched['rows'], 'product'));
        $this->assertSame(80.0, (float) $searched['rows'][0]['transaction_contribution']);
        $this->assertSame(40.0, (float) $searched['rows'][0]['revenue_contribution']);
        $this->assertSame(100, $searched['totals']['success_purchases']);
        $this->assertSame(1, $searched['pagination']['total']);

        $page2 = $this->table(['app-a'], ['sort' => 'revenue', 'direction' => 'desc', 'per_page' => 1, 'page' => 2]);
        $this->assertSame(['Produk A'], array_column($page2['rows'], 'product'));
        $this->assertSame(80.0, (float) $page2['rows'][0]['transaction_contribution']);
        $this->assertSame(40.0, (float) $page2['rows'][0]['revenue_contribution']);
        $this->assertSame(['current_page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2, 'from' => 2, 'to' => 2], $page2['pagination']);
        $this->assertSame(['search' => null, 'sort' => 'revenue', 'direction' => 'desc'], $page2['query']);
    }

    public function test_search_without_matches_keeps_the_query_and_the_divisors(): void
    {
        $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 100000);

        $table = $this->table(['app-a'], ['search' => 'tidak ada']);

        $this->assertSame([], $table['rows']);
        $this->assertFalse($table['is_empty']);
        $this->assertSame('tidak ada', $table['query']['search']);
        $this->assertSame(0, $table['pagination']['total']);
        $this->assertSame(1, $table['totals']['products']);
    }

    public function test_sorts_by_any_column_in_both_directions(): void
    {
        $this->transaction($this->appA, 'Beta', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 300000);
        $this->transaction($this->appA, 'Alpha', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 100000);
        $this->transaction($this->appA, 'Alpha', '2026-11-05 10:00:00', null, 0, MarkazTransactionStatus::Pending);

        $this->assertSame(['Beta', 'Alpha'], array_column($this->table(['app-a'])['rows'], 'product'));
        $this->assertSame(['Alpha', 'Beta'], array_column($this->table(['app-a'], ['sort' => 'product'])['rows'], 'product'));
        $this->assertSame(['Alpha', 'Beta'], array_column($this->table(['app-a'], ['sort' => 'total_transactions'])['rows'], 'product'));
        $this->assertSame(['Alpha', 'Beta'], array_column($this->table(['app-a'], ['sort' => 'revenue_contribution', 'direction' => 'asc'])['rows'], 'product'));
    }

    public function test_ac03_export_dataset_has_every_product_without_search_or_pages(): void
    {
        foreach (['Produk A', 'Produk B', 'Produk C'] as $i => $product) {
            $this->transaction($this->appA, $product, '2026-11-05 09:00:00', '2026-11-05 09:05:00', ($i + 1) * 100000);
        }
        $this->transaction($this->appB, 'Produk A', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 50000);

        $pages = [
            ...$this->table([], ['per_page' => 2, 'page' => 1])['rows'],
            ...$this->table([], ['per_page' => 2, 'page' => 2])['rows'],
        ];

        $period = app(PeriodResolverService::class)->resolve(
            new PeriodSelection(PeriodPreset::from('last_7_days'), PeriodComparison::Auto, [], PeriodSelection::DATASET_TRANSACTIONS),
        );
        $export = app(ProductPerformanceService::class)->all($period);

        $this->assertCount(4, $export['rows']);
        $this->assertEquals($pages, $export['rows']);
        $this->assertArrayNotHasKey('pagination', $export);
        $this->assertSame(4, $export['totals']['products']);
    }

    public function test_ac04_dataset_is_served_from_the_performance_dashboard_route(): void
    {
        $this->assertSame(url(self::URL), route('api.performance.products'));
        $this->assertFalse(app('router')->has('api.products.index'));

        $this->actingAs($this->manager)->getJson(self::URL.'?preset=last_7_days')->assertOk();
    }

    public function test_ac05_group_purchase_pending_and_claim_and_recalculated_contributions(): void
    {
        $buyer = $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 300000);
        // A member of the group package never adds a count or a value.
        $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 300000, refId: (string) $buyer->markaz_transaction_id);
        $this->transaction($this->appA, 'Produk A', '2026-11-05 10:00:00', null, 0, MarkazTransactionStatus::Pending);
        $this->transaction($this->appA, 'Produk A', '2026-11-05 11:00:00', '2026-11-05 11:00:00', 0, claim: true);
        $this->transaction($this->appA, 'Produk B', '2026-11-06 09:00:00', '2026-11-06 09:05:00', 100000);

        $rows = $this->rowsByProduct($this->table(['app-a']));
        $a = $rows['Produk A'];

        $this->assertSame(3, $a['total_transactions']);
        $this->assertSame(1, $a['success_purchases']);
        $this->assertSame(1, $a['pending_purchases']);
        $this->assertSame(['purchase_attempts' => 2, 'claim_checkouts' => 1], $a['total_breakdown']);
        $this->assertEquals(300000, $a['revenue']);
        $this->assertSame(50.0, (float) $a['transaction_contribution']);
        $this->assertSame(50.0, (float) $rows['Produk B']['transaction_contribution']);
        $this->assertSame(75.0, (float) $a['revenue_contribution']);
        $this->assertSame(25.0, (float) $rows['Produk B']['revenue_contribution']);

        $buyer->update(['source_status' => MarkazTransactionStatus::Failed->value, 'paid_date' => null, 'paid_time' => null]);

        $rows = $this->rowsByProduct($this->table(['app-a']));

        $this->assertSame(0, $rows['Produk A']['success_purchases']);
        $this->assertSame(1, $rows['Produk A']['unsuccessful_purchases']);
        $this->assertSame(0.0, (float) $rows['Produk A']['transaction_contribution']);
        $this->assertSame(100.0, (float) $rows['Produk B']['transaction_contribution']);
        $this->assertSame(0.0, (float) $rows['Produk A']['revenue_contribution']);
        $this->assertSame(100.0, (float) $rows['Produk B']['revenue_contribution']);
    }

    public function test_unsuccessful_breakdown_adds_up_to_tidak_berhasil(): void
    {
        foreach ([[MarkazTransactionStatus::Expired, 10], [MarkazTransactionStatus::Canceled, 6], [MarkazTransactionStatus::Failed, 4]] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', null, 0, $status);
            }
        }

        $a = $this->rowsByProduct($this->table(['app-a']))['Produk A'];

        $this->assertSame(['expired' => 10, 'canceled' => 6, 'failed' => 4], $a['unsuccessful_breakdown']);
        $this->assertSame(20, $a['unsuccessful_purchases']);
    }

    public function test_omzet_follows_the_paid_date_and_counts_follow_the_created_date(): void
    {
        // Created before the active period, paid inside it.
        $this->transaction($this->appA, 'Produk A', '2026-11-03 22:00:00', '2026-11-05 08:00:00', 90000);

        $a = $this->rowsByProduct($this->table(['app-a']))['Produk A'];

        $this->assertSame(0, $a['total_transactions']);
        $this->assertSame(0, $a['success_purchases']);
        $this->assertEquals(90000, $a['revenue']);
        $this->assertNull($a['transaction_contribution']);
        $this->assertSame(100.0, (float) $a['revenue_contribution']);
    }

    public function test_a_zero_divisor_differs_from_data_that_is_not_available(): void
    {
        $this->transaction($this->appA, 'Produk A', '2026-11-05 09:00:00', null, 0, MarkazTransactionStatus::Pending);

        $zero = $this->table(['app-a']);

        $this->assertSame(['transaction' => 'zero_divisor', 'revenue' => 'zero_divisor'], $zero['contribution']);
        $this->assertNull($zero['rows'][0]['transaction_contribution']);
        $this->assertSame(0, $zero['totals']['success_purchases']);

        $unavailable = $this->table(['app-a'], ['preset' => 'last_30_days']);

        $this->assertSame('unavailable', $unavailable['active']['availability']);
        $this->assertSame(['transaction' => 'unavailable', 'revenue' => 'unavailable'], $unavailable['contribution']);
        $this->assertNull($unavailable['totals']['success_purchases']);
        $this->assertSame('Data periode aktif belum tersedia.', $unavailable['message']);
        $this->assertSame([], $unavailable['rows']);
    }

    public function test_empty_period_has_its_own_message(): void
    {
        $table = $this->table(['app-a']);

        $this->assertTrue($table['is_empty']);
        $this->assertSame('Tidak ada data produk untuk filter yang dipilih.', $table['message']);
        $this->assertSame(['transaction' => 'zero_divisor', 'revenue' => 'zero_divisor'], $table['contribution']);
    }

    public function test_filter_and_table_controls_are_validated_on_the_server(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)->assertUnprocessable()->assertJsonValidationErrors('preset');
        $this->getJson(self::URL.'?preset=last_7_days&sort=nominal')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson(self::URL.'?preset=last_7_days&direction=up')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson(self::URL.'?preset=last_7_days&per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson(self::URL.'?preset=last_7_days&page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson(self::URL.'?preset=last_7_days&search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson(self::URL.'?preset=last_7_days&platforms[]=unknown')->assertUnprocessable()->assertJsonValidationErrors('platforms.0');
    }

    public function test_guests_cannot_read_the_dataset(): void
    {
        $this->getJson(self::URL.'?preset=last_7_days')->assertUnauthorized();
    }

    /**
     * @param  list<string>  $platforms
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    private function table(array $platforms, array $query = []): array
    {
        $params = http_build_query(['preset' => 'last_7_days', 'platforms' => $platforms, ...$query]);

        return $this->actingAs($this->manager)
            ->getJson(self::URL.'?'.$params)
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, array<string, mixed>>
     */
    private function rowsByProduct(array $table): array
    {
        return array_column($table['rows'], null, 'product');
    }

    private function coverage(Platform $platform): void
    {
        DashboardSnapshot::factory()->for($platform)->create([
            'date_from' => '2026-11-04',
            'date_to' => '2026-11-10',
            'cutoff_at' => '2026-11-11 00:00:00',
            'is_complete' => true,
            'payload' => [],
            'recorded_at' => '2026-11-11 00:00:00',
        ]);
    }

    private function transaction(
        Platform $platform,
        ?string $product,
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($platform)->create([
            'product' => $product,
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
        ]);
    }
}
