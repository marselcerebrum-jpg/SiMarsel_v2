<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoTrendTopCodesTest extends TestCase
{
    use RefreshDatabase;

    private const TREND_URL = '/api/promo-analysis/trend';

    private const TOP_URL = '/api/promo-analysis/top-codes';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
        app(PromoCodeService::class)->register($this->jadiasn->id, 'APP26');
        app(PromoCodeService::class)->register($this->jadiasn->id, 'RESELLER01');
        // Codes are registered before the transactions; "today" is after the analysed months.
        $this->travelTo(CarbonImmutable::create(2026, 10, 15, 8));
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(?array $codes, float $nominal, string $paidDate, ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'promo_code' => $codes,
            'nominal' => $nominal,
        ]);
    }

    /**
     * September 2026, in three weeks: 6 transactions (2.150.000), 4 with promo (1.150.000).
     */
    private function seedTrendData(): void
    {
        // Week of 31 Aug - 6 Sep.
        $this->transaction(['APP26'], 100000, '2026-09-01');
        $this->transaction(null, 400000, '2026-09-01');
        // Week of 7 - 13 Sep: a two-code transaction counts once with its full nominal.
        $this->transaction(['APP26', 'RESELLER01'], 200000, '2026-09-08');
        $this->transaction(['', 'RESELLER01'], 300000, '2026-09-10');
        // Week of 14 - 20 Sep.
        $this->transaction(['UNREG26'], 550000, '2026-09-15', $this->cerebrum);
        $this->transaction(null, 600000, '2026-09-20');

        $this->transaction(['APP26'], 999999, '2026-10-01');
        Transaction::factory()->for($this->jadiasn)->failed()->create(['paid_date' => '2026-09-02', 'promo_code' => ['APP26'], 'nominal' => 999999]);
    }

    private function trend(string $query): array
    {
        return $this->actingAs($this->manager)->getJson(self::TREND_URL.$query)->assertOk()->json('data');
    }

    private function top(string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)->getJson(self::TOP_URL.$query)->assertOk()->json('data');
    }

    /**
     * @return array<string, list<int|float|null>>
     */
    private function datasets(array $trend): array
    {
        return array_column($trend['datasets'], 'data', 'key');
    }

    public function test_guest_cannot_view_the_trend_or_top_codes(): void
    {
        $this->getJson(self::TREND_URL)->assertUnauthorized();
        $this->getJson(self::TOP_URL)->assertUnauthorized();
    }

    public function test_weekly_trend_counts_each_promo_transaction_once_with_a_total_sales_overlay(): void
    {
        $this->seedTrendData();

        $trend = $this->trend(self::SEPTEMBER.'&period=weekly');

        $this->assertSame('weekly', $trend['period']);
        $this->assertSame(['01 - 06 Sep 2026', '07 - 13 Sep 2026', '14 - 20 Sep 2026', '21 - 27 Sep 2026', '28 - 30 Sep 2026'], $trend['labels']);
        $this->assertEquals([
            'promo_transactions' => [1, 2, 1, 0, 0],
            'promo_revenue' => [100000, 500000, 550000, 0, 0],
            'total_transactions' => [2, 2, 2, 0, 0],
            'total_sales' => [500000, 500000, 1150000, 0, 0],
        ], $this->datasets($trend));
        $this->assertEquals(['promo_transactions' => 4, 'promo_revenue' => 1150000, 'total_transactions' => 6, 'total_sales' => 2150000], $trend['totals']);
        $this->assertSame(
            ['Transaksi dengan Promo', 'Revenue dengan Promo', 'Total Transaksi', 'Total Penjualan'],
            array_column($trend['datasets'], 'label'),
        );
        $this->assertEquals(
            ['label' => '07 - 13 Sep 2026', 'period_start' => '2026-09-07', 'period_end' => '2026-09-13', 'promo_transactions' => 2, 'promo_revenue' => 500000, 'total_transactions' => 2, 'total_sales' => 500000],
            $trend['rows'][1],
        );
    }

    public function test_trend_totals_match_the_kpi_summary(): void
    {
        $this->seedTrendData();

        $trend = $this->trend(self::SEPTEMBER);
        $kpis = $this->getJson('/api/promo-analysis/summary'.self::SEPTEMBER)->json('data.kpis');

        $this->assertSame([$kpis['promo_transactions']['value'], $kpis['promo_transactions']['value'] + $kpis['non_promo_transactions']['value']], [
            $trend['totals']['promo_transactions'], $trend['totals']['total_transactions'],
        ]);
        $this->assertEquals([$kpis['promo_revenue']['value'], $kpis['promo_revenue']['value'] + $kpis['non_promo_revenue']['value']], [
            $trend['totals']['promo_revenue'], $trend['totals']['total_sales'],
        ]);
    }

    public function test_daily_is_the_default_and_monthly_buckets_follow_the_range(): void
    {
        $this->seedTrendData();

        $daily = $this->trend(self::SEPTEMBER);
        $this->assertSame(['daily', 30, '01 Sep 2026'], [$daily['period'], count($daily['labels']), $daily['labels'][0]]);
        $this->assertSame([2, 1], [$this->datasets($daily)['total_transactions'][0], $this->datasets($daily)['promo_transactions'][0]]);

        $monthly = $this->trend('?date_from=2026-08-20&date_to=2026-10-05&period=monthly');
        $this->assertSame(['Aug 2026', 'Sep 2026', 'Oct 2026'], $monthly['labels']);
        $this->assertEquals([0, 4, 1], $this->datasets($monthly)['promo_transactions']);
        $this->assertSame('2026-10-05', $monthly['rows'][2]['period_end']);
    }

    public function test_buckets_after_today_have_no_values(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 10, 8));
        $this->seedTrendData();

        $values = $this->datasets($this->trend(self::SEPTEMBER.'&period=weekly'));

        $this->assertSame([1, 2, null, null, null], $values['promo_transactions']);
        $this->assertSame([500000, 500000, null, null, null], $values['total_sales']);
    }

    public function test_code_search_narrows_the_promo_lines_but_not_the_total_sales_overlay(): void
    {
        $this->seedTrendData();

        $trend = $this->trend(self::SEPTEMBER.'&period=weekly&search=reseller');

        $this->assertEquals([0, 2, 0, 0, 0], $this->datasets($trend)['promo_transactions']);
        $this->assertEquals(['promo_transactions' => 2, 'promo_revenue' => 500000, 'total_transactions' => 6, 'total_sales' => 2150000], $trend['totals']);
        $this->assertSame('reseller', $trend['filter']['search']);
    }

    public function test_platform_and_payment_method_filters_apply_to_the_overlay_too(): void
    {
        $this->seedTrendData();

        $cerebrum = $this->trend(self::SEPTEMBER.'&platforms[]=cerebrum');
        $this->assertEquals(['promo_transactions' => 1, 'promo_revenue' => 550000, 'total_transactions' => 1, 'total_sales' => 550000], $cerebrum['totals']);

        Transaction::factory()->for($this->jadiasn)->withoutPaymentMethod()->create(['paid_date' => '2026-09-25', 'promo_code' => null, 'nominal' => 80000]);
        $unknown = $this->trend(self::SEPTEMBER.'&payment_methods[]=Tidak%20diketahui');
        $this->assertEquals(['promo_transactions' => 0, 'promo_revenue' => 0, 'total_transactions' => 1, 'total_sales' => 80000], $unknown['totals']);
    }

    public function test_anomalous_transactions_are_left_out_of_the_promo_lines_and_the_overlay(): void
    {
        $this->seedTrendData();
        $anomaly = $this->transaction(['APP26'], 70000, '2026-09-25');
        $anomaly->update(['promo_code' => ['APP26', 'RESELLER01', 'EXTRA']]);
        $this->assertTrue($anomaly->fresh()->promo_code_anomaly);

        $this->assertEquals(
            ['promo_transactions' => 4, 'promo_revenue' => 1150000, 'total_transactions' => 6, 'total_sales' => 2150000],
            $this->trend(self::SEPTEMBER)['totals'],
        );
        $this->assertSame(2, collect($this->top()['codes'])->firstWhere('code', 'APP26')['usages']);
    }

    public function test_trend_validation(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::TREND_URL.'?period=yearly')
            ->assertUnprocessable()->assertJsonValidationErrors(['period' => 'Periode tidak valid.']);
        $this->getJson(self::TREND_URL.'?date_from=2025-01-01&date_to=2026-06-30&period=daily')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to' => 'Rentang tanggal untuk periode daily maksimal 366 hari.']);
        $this->getJson(self::TREND_URL.'?date_from=2025-01-01&date_to=2026-06-30&period=monthly')->assertOk();
        $this->getJson(self::TREND_URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
    }

    public function test_empty_trend(): void
    {
        $trend = $this->trend(self::SEPTEMBER.'&period=weekly');

        $this->assertTrue($trend['is_empty']);
        $this->assertSame('Data tidak tersedia untuk filter yang dipilih.', $trend['message']);
        $this->assertSame([0, 0, 0, 0, 0], $this->datasets($trend)['promo_transactions']);
    }

    public function test_top_codes_are_ranked_by_usages_with_attributed_revenue(): void
    {
        $this->seedTrendData();
        foreach (['C1', 'C2', 'C3'] as $code) {
            $this->transaction([$code], 900000, '2026-09-21');
        }
        $this->transaction(['C1'], 10000, '2026-09-22');

        $top = $this->top();

        // Usages decide the rank, revenue only breaks ties: APP26 (2 usages, 100.000) ranks above C2 (1 usage, 900.000).
        // UNREG26 (1 usage, 550.000) falls out of the five.
        $this->assertEquals([
            [1, 'C1', 'jadiasn', false, 2, 910000],
            [2, 'RESELLER01', 'jadiasn', true, 2, 500000],
            [3, 'APP26', 'jadiasn', true, 2, 100000],
            [4, 'C2', 'jadiasn', false, 1, 900000],
            [5, 'C3', 'jadiasn', false, 1, 900000],
        ], array_map(fn (array $row) => [$row['rank'], $row['code'], $row['platform']['slug'], $row['is_registered'], $row['usages'], $row['revenue']], $top['codes']));

        // Share of the revenue of every filtered transaction (4.860.000).
        $this->assertEquals(2.06, $top['codes'][2]['contribution_percentage']);
        $this->assertSame(['platform_id' => $this->jadiasn->id, 'code' => 'APP26'], $top['codes'][2]['detail']);
        $this->assertSame(['APP26'], $top['codes'][2]['raw_codes']);
        $this->assertFalse($top['is_empty']);
    }

    public function test_top_codes_use_the_same_usages_as_the_rekap_and_merge_metadata_snapshots(): void
    {
        $this->seedTrendData();
        $app = PromoCode::where('code', 'APP26')->first();
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 8));
        app(PromoCodeService::class)->updateMetadata($app, Funnel::where('name', 'Marketing')->value('id'), null);
        $this->transaction(['APP26'], 50000, '2026-09-20');

        $rekap = $this->actingAs($this->manager)->getJson('/api/promo-analysis/codes'.self::SEPTEMBER.'&search=app26')->json();
        $top = collect($this->top()['codes'])->firstWhere('code', 'APP26');

        // The rekap splits APP26 by metadata snapshot; Top 5 counts the code once.
        $this->assertCount(2, $rekap['data']);
        $this->assertEquals([array_sum(array_column($rekap['data'], 'usages')), array_sum(array_column($rekap['data'], 'revenue'))], [$top['usages'], $top['revenue']]);
        $this->assertSame([1, 3], [$top['rank'], $top['usages']]);
    }

    public function test_top_codes_apply_the_shared_filters(): void
    {
        $this->seedTrendData();

        $this->assertSame([['UNREG26', 'cerebrum']], array_map(
            fn (array $row) => [$row['code'], $row['platform']['slug']],
            $this->top(self::SEPTEMBER.'&platforms[]=cerebrum')['codes'],
        ));
        $this->assertSame(['RESELLER01'], array_column($this->top(self::SEPTEMBER.'&search=reseller')['codes'], 'code'));
        $this->assertSame(['APP26'], array_column($this->top('?year=2026&month=10')['codes'], 'code'));

        $empty = $this->top('?year=2026&month=11');
        $this->assertSame([true, []], [$empty['is_empty'], $empty['codes']]);
    }
}
