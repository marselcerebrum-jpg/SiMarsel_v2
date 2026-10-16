<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceFilterApiTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    private Platform $platformC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 16, 13, 45));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
        $this->platformC = Platform::factory()->create(['slug' => 'app-c', 'name' => 'App C']);

        $this->seedFilterData();
    }

    private function transaction(Platform $platform, string $date, float $nominal, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'paid_time' => '10:00:00',
            'payment_method' => 'BNI',
            'nominal' => $nominal,
            'product_type' => Transaction::TYPE_FIRST,
            'status_message' => $status,
        ]);
    }

    /**
     * Revenue per year: 2024 Rp100.000, 2025 Rp250.000, 2026 Rp900.000 (Oct 2026: Rp30.000 so far).
     */
    private function seedFilterData(): void
    {
        $this->transaction($this->platformA, '2024-03-10', 100000);
        $this->transaction($this->platformA, '2025-03-10', 200000);
        $this->transaction($this->platformB, '2025-07-01', 50000);
        $this->transaction($this->platformA, '2026-03-15', 300000);
        $this->transaction($this->platformB, '2026-03-20', 100000);
        $this->transaction($this->platformA, '2026-09-05', 400000);
        $this->transaction($this->platformC, '2026-09-10', 70000);
        $this->transaction($this->platformA, '2026-09-12', 999999, 'failed');
        $this->transaction($this->platformA, '2026-10-02', 10000);
        $this->transaction($this->platformB, '2026-10-15', 20000);

        Downloader::factory()->for($this->platformA)->create(['date' => '2026-09-01', 'total' => 50]);
        Downloader::factory()->for($this->platformA)->create(['date' => '2026-10-01', 'total' => 100]);
    }

    private function summary(string $query = ''): array
    {
        return $this->actingAs($this->manager)
            ->getJson('/api/performance/summary'.$query)
            ->assertOk()
            ->json('data');
    }

    public function test_without_a_period_the_current_month_until_today_is_used(): void
    {
        $summary = $this->summary();

        $this->assertSame('current_month', $summary['filter']['mode']);
        $this->assertSame(['2026-10-01', '2026-10-16'], [$summary['filter']['date_from'], $summary['filter']['date_to']]);
        $this->assertSame(['2026-09-15', '2026-09-30'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertEquals(30000, $summary['kpis']['total_revenue']['value']);
        $this->assertSame(2, $summary['kpis']['total_transactions']['value']);
    }

    public function test_month_shows_that_month_and_compares_with_the_month_before(): void
    {
        $summary = $this->summary('?year=2026&month=9');

        $this->assertSame('month', $summary['filter']['mode']);
        $this->assertSame([2026], $summary['filter']['years']);
        $this->assertSame(9, $summary['filter']['month']);
        $this->assertSame(['2026-08-01', '2026-08-31'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertEquals(470000, $summary['kpis']['total_revenue']['value']);
        $this->assertEquals(0, $summary['kpis']['total_revenue']['previous_value']);
        $this->assertSame(50, $summary['kpis']['total_downloaders']['value']);
    }

    public function test_several_years_are_combined_and_compared_with_each_year_before(): void
    {
        $summary = $this->summary('?years[]=2026&years[]=2025');

        $this->assertSame('years', $summary['filter']['mode']);
        $this->assertSame([2025, 2026], $summary['filter']['years']);
        $this->assertEquals(1150000, $summary['kpis']['total_revenue']['value']);
        $this->assertSame(8, $summary['kpis']['total_transactions']['value']);

        // Compared with 2024 and 2025: Rp100.000 + Rp250.000.
        $this->assertEquals(350000, $summary['kpis']['total_revenue']['previous_value']);
        $this->assertSame('up', $summary['kpis']['total_revenue']['trend']);
    }

    public function test_non_consecutive_years_only_count_the_selected_years(): void
    {
        $summary = $this->summary('?years[]=2024&years[]=2026');

        $this->assertCount(2, $summary['filter']['ranges']);
        $this->assertEquals(1000000, $summary['kpis']['total_revenue']['value']);

        // Compared with 2023 and 2025.
        $this->assertEquals(250000, $summary['kpis']['total_revenue']['previous_value']);
        $this->assertSame([
            ['date_from' => '2023-01-01', 'date_to' => '2023-12-31'],
            ['date_from' => '2025-01-01', 'date_to' => '2025-12-31'],
        ], $summary['previous_period']['ranges']);
    }

    public function test_year_range_covers_every_year_in_between(): void
    {
        $summary = $this->summary('?year_from=2025&year_to=2026');

        $this->assertSame('year_range', $summary['filter']['mode']);
        $this->assertSame([2025, 2026], $summary['filter']['years']);
        $this->assertEquals(1150000, $summary['kpis']['total_revenue']['value']);

        // Compared with 2023 - 2024.
        $this->assertEquals(100000, $summary['kpis']['total_revenue']['previous_value']);
    }

    public function test_several_platforms_filter_every_widget(): void
    {
        $query = '?year=2026&month=9&platforms[]=app-a&platforms[]=app-b';

        $this->assertEquals(400000, $this->summary($query)['kpis']['total_revenue']['value']);

        $this->getJson('/api/performance/payment-methods'.$query)
            ->assertOk()
            ->assertJsonPath('data.totals.revenue', 400000)
            ->assertJsonPath('data.filter.platforms', ['app-a', 'app-b']);

        $this->getJson('/api/performance/platform-contribution'.$query)
            ->assertOk()
            ->assertJsonPath('data.totals.platforms', 2)
            ->assertJsonPath('data.platforms.*.slug', ['app-a', 'app-b']);

        $this->getJson('/api/performance/purchase-times'.$query)
            ->assertOk()
            ->assertJsonPath('data.totals.transactions', 1);

        $this->getJson('/api/performance/trend'.$query.'&metric=revenue')
            ->assertOk()
            ->assertJsonPath('data.rows.4.value', 400000)
            ->assertJsonPath('data.rows.9.value', 0);
    }

    public function test_old_single_platform_parameter_still_works(): void
    {
        $summary = $this->summary('?year=2026&month=9&platform=app-c');

        $this->assertSame('app-c', $summary['filter']['platform']);
        $this->assertSame(['app-c'], $summary['filter']['platforms']);
        $this->assertEquals(70000, $summary['kpis']['total_revenue']['value']);
    }

    public function test_every_widget_uses_the_same_filter(): void
    {
        $query = '?years[]=2025&years[]=2026';
        $revenue = $this->summary($query)['kpis']['total_revenue']['value'];

        $this->getJson('/api/performance/payment-methods'.$query)->assertJsonPath('data.totals.revenue', $revenue);
        $this->getJson('/api/performance/platform-contribution'.$query)->assertJsonPath('data.totals.revenue', $revenue);
        $this->getJson('/api/performance/purchase-times'.$query)->assertJsonPath('data.totals.transactions', 8);
        $this->assertEquals(
            $revenue,
            array_sum($this->getJson('/api/performance/trend'.$query.'&metric=revenue')->json('data.rows.*.value')),
        );
    }

    public function test_period_filters_are_validated(): void
    {
        $this->actingAs($this->manager);

        $this->getJson('/api/performance/summary?date_from=2026-09-01&date_to=2026-09-30&year=2026&month=9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter' => 'Pilih salah satu filter periode saja: rentang tanggal, bulan, tahun, atau rentang tahun.']);

        $this->getJson('/api/performance/summary?month=9')
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

        $this->getJson('/api/performance/summary?year=2026&month=13')
            ->assertJsonValidationErrors(['month' => 'Bulan harus di antara 1 dan 12.']);

        $this->getJson('/api/performance/summary?years[]=1999&years[]=2027')
            ->assertJsonValidationErrors(['years.0', 'years.1']);

        $this->getJson('/api/performance/summary?years[]=2026&years[]=2026')
            ->assertJsonValidationErrors(['years.0' => 'Tahun tidak boleh dipilih lebih dari sekali.']);

        $this->getJson('/api/performance/summary?'.http_build_query(['years' => [2020, 2021, 2022, 2023, 2024, 2025]]))
            ->assertJsonValidationErrors(['years' => 'Tahun maksimal 5 pilihan.']);

        $this->getJson('/api/performance/summary?year_from=2026&year_to=2024')
            ->assertJsonValidationErrors(['year_to' => 'Tahun akhir tidak boleh sebelum tahun mulai.']);

        $this->getJson('/api/performance/summary?platforms[]=app-a&platforms[]=unknown')
            ->assertJsonValidationErrors(['platforms.1' => 'Platform tidak ditemukan.']);
    }

    public function test_trend_uses_daily_for_a_month_and_monthly_for_years_by_default(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/performance/trend?year=2026&month=9&metric=revenue')
            ->assertOk()
            ->assertJsonPath('data.period', 'daily')
            ->assertJsonCount(30, 'data.labels');

        $this->getJson('/api/performance/trend?years[]=2026&metric=revenue')
            ->assertOk()
            ->assertJsonPath('data.period', 'monthly')
            ->assertJsonPath('data.labels.0', 'Jan 2026')
            ->assertJsonPath('data.datasets', [[
                'label' => 'Revenue',
                'data' => [0, 0, 400000, 0, 0, 0, 0, 0, 470000, 30000, null, null],
            ]]);
    }

    public function test_current_month_is_compared_with_the_whole_previous_month(): void
    {
        $summary = $this->summary('?year=2026&month=10');

        $this->assertSame(['2026-10-01', '2026-10-31'], [$summary['filter']['date_from'], $summary['filter']['date_to']]);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertEquals(30000, $summary['kpis']['total_revenue']['value']);
        $this->assertEquals(470000, $summary['kpis']['total_revenue']['previous_value']);
        $this->assertSame('down', $summary['kpis']['total_revenue']['trend']);
    }

    public function test_trend_days_after_today_have_no_value_or_change_yet(): void
    {
        $rows = $this->actingAs($this->manager)
            ->getJson('/api/performance/trend?year=2026&month=10&metric=revenue')
            ->assertOk()
            ->assertJsonCount(31, 'data.labels')
            ->assertJsonPath('data.datasets.0.data.14', 20000)
            ->assertJsonPath('data.datasets.0.data.15', 0)
            ->assertJsonPath('data.datasets.0.data.16', null)
            ->json('data.rows');

        // 15 Oct has a sale; 16 Oct is today, so it still has a value and a change.
        $this->assertSame([20000, null, 'up'], [$rows[14]['value'], $rows[14]['change_percentage'], $rows[14]['trend']]);
        $this->assertSame([0, -100, 'down'], [$rows[15]['value'], $rows[15]['change_percentage'], $rows[15]['trend']]);
        $this->assertSame([null, null, null], [$rows[16]['value'], $rows[16]['change_percentage'], $rows[16]['trend']]);
        $this->assertSame([null, null, null], [$rows[30]['value'], $rows[30]['change_percentage'], $rows[30]['trend']]);
    }

    public function test_trend_compares_several_years_month_by_month(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/performance/trend?years[]=2025&years[]=2026&metric=revenue')
            ->assertOk()
            ->assertJsonPath('data.period', 'monthly')
            ->assertJsonPath('data.labels', ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'])
            ->assertJsonPath('data.datasets', [
                ['label' => '2025', 'data' => [0, 0, 200000, 0, 0, 0, 50000, 0, 0, 0, 0, 0]],
                ['label' => '2026', 'data' => [0, 0, 400000, 0, 0, 0, 0, 0, 470000, 30000, null, null]],
            ])
            ->assertJsonCount(24, 'data.rows')
            ->assertJsonPath('data.rows.0.label', 'Jan 2025')
            ->assertJsonPath('data.rows.12.label', 'Jan 2026')
            // Jul 2026 (0) is compared with Jul 2025 (Rp50.000).
            ->assertJsonPath('data.rows.18.label', 'Jul 2026')
            ->assertJsonPath('data.rows.18.change_percentage', -100)
            ->assertJsonPath('data.rows.18.trend', 'down');
    }

    public function test_trend_rows_compare_each_month_with_the_same_month_of_the_previous_selected_year(): void
    {
        $rows = collect($this->actingAs($this->manager)
            ->getJson('/api/performance/trend?years[]=2024&years[]=2026&metric=revenue')
            ->assertOk()
            ->json('data.rows'))
            ->keyBy('label')
            ->map(fn (array $row) => [$row['value'], $row['change_percentage'], $row['trend']]);

        // The first selected year has nothing to compare with.
        $this->assertSame([100000, null, null], $rows['Mar 2024']);
        $this->assertSame([0, null, null], $rows['Dec 2024']);

        // Jan 2026 is compared with Jan 2024, not with Dec 2024.
        $this->assertSame([0, null, 'same'], $rows['Jan 2026']);
        $this->assertSame([400000, 300, 'up'], $rows['Mar 2026']);
        $this->assertSame([0, null, 'same'], $rows['Apr 2026']);
        $this->assertSame([470000, null, 'up'], $rows['Sep 2026']);

        // Months after today have no value or change yet.
        $this->assertSame([null, null, null], $rows['Nov 2026']);
    }

    public function test_trend_period_must_fit_the_selected_years(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/performance/trend?years[]=2025&years[]=2026&metric=revenue&period=weekly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period' => 'Perbandingan beberapa tahun hanya mendukung periode monthly.']);

        $this->getJson('/api/performance/trend?year_from=2024&year_to=2026&metric=revenue&period=daily')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to' => 'Rentang tanggal untuk periode daily maksimal 366 hari.']);
    }
}
