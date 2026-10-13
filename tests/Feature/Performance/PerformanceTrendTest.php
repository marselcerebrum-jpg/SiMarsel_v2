<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PerformanceTrendTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/trend';

    private const WEEKS_OF_SEPTEMBER = [
        '01 - 06 Sep 2026',
        '07 - 13 Sep 2026',
        '14 - 20 Sep 2026',
        '21 - 27 Sep 2026',
        '28 - 30 Sep 2026',
    ];

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b']);
    }

    private function transaction(Platform $platform, string $date, ?string $email, float $nominal, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'customer_email' => $email,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    private function downloader(Platform $platform, string $date, int $total): void
    {
        Downloader::factory()->for($platform)->create(['date' => $date, 'total' => $total]);
    }

    /**
     * September 2026 starts on a Tuesday, so the first and last weeks are partial.
     */
    private function seedTrendData(): void
    {
        $this->transaction($this->platformA, '2026-07-15', 'alice@example.com', 100000);

        $this->transaction($this->platformA, '2026-09-02', 'alice@example.com', 200000);
        $this->transaction($this->platformA, '2026-09-05', ' ALICE@example.com ', 100000);
        $this->transaction($this->platformA, '2026-09-08', 'bob@example.com', 300000);
        $this->transaction($this->platformA, '2026-09-10', 'dave@example.com', 999999, 'failed');
        $this->transaction($this->platformA, '2026-09-15', null, 50000);
        $this->transaction($this->platformA, '2026-09-30', 'carol@example.com', 400000);
        $this->downloader($this->platformA, '2026-09-01', 40);
        $this->downloader($this->platformA, '2026-09-08', 100);
        $this->downloader($this->platformA, '2026-09-30', 20);

        $this->transaction($this->platformB, '2026-09-03', 'erin@example.com', 70000);
        $this->downloader($this->platformB, '2026-09-03', 60);
    }

    private function weeklyTrend(string $metric, ?string $platform = 'app-a'): TestResponse
    {
        $query = http_build_query(array_filter([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'platform' => $platform,
            'metric' => $metric,
            'period' => 'weekly',
        ]));

        return $this->actingAs($this->manager)->getJson(self::URL.'?'.$query)->assertOk();
    }

    public function test_guest_cannot_view_trend(): void
    {
        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&metric=revenue&period=daily')
            ->assertUnauthorized();
    }

    public function test_filters_metric_and_period_are_validated(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to', 'metric', 'period']);

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&metric=profit&period=yearly')
            ->assertJsonValidationErrors(['metric' => 'Metric tidak valid.', 'period' => 'Periode tidak valid.']);
    }

    public function test_daily_and_weekly_range_is_limited_to_366_days(): void
    {
        $this->actingAs($this->manager);

        foreach (['daily', 'weekly'] as $period) {
            $this->getJson(self::URL."?date_from=2025-01-01&date_to=2026-01-02&metric=revenue&period={$period}")
                ->assertJsonValidationErrors(['date_to' => "Rentang tanggal untuk periode {$period} maksimal 366 hari."]);

            $this->getJson(self::URL."?date_from=2025-01-01&date_to=2026-01-01&metric=revenue&period={$period}")
                ->assertOk();
        }

        $this->getJson(self::URL.'?date_from=2024-01-01&date_to=2026-12-31&metric=revenue&period=monthly')
            ->assertOk()
            ->assertJsonCount(36, 'data.labels');
    }

    public function test_weekly_buckets_start_on_monday_and_follow_the_selected_range(): void
    {
        $this->seedTrendData();

        $this->weeklyTrend('revenue')
            ->assertJsonPath('data.metric', ['key' => 'revenue', 'label' => 'Revenue', 'unit' => 'currency'])
            ->assertJsonPath('data.period', 'weekly')
            ->assertJsonPath('data.labels', self::WEEKS_OF_SEPTEMBER)
            ->assertJsonPath('data.datasets.0.label', 'Revenue')
            ->assertJsonPath('data.datasets.0.data', [300000, 300000, 50000, 0, 400000])
            ->assertJsonPath('data.rows.0', [
                'label' => '01 - 06 Sep 2026',
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-06',
                'value' => 300000,
            ])
            ->assertJsonPath('data.rows.4.period_start', '2026-09-28')
            ->assertJsonPath('data.rows.4.period_end', '2026-09-30')
            ->assertJsonPath('data.is_empty', false);
    }

    public function test_every_metric_is_calculated_per_bucket(): void
    {
        $this->seedTrendData();

        $expected = [
            'transactions' => [2, 1, 1, 0, 1],
            'downloaders' => [40, 100, 0, 0, 20],
            'conversion' => [2.5, 1, null, null, 5],
            'new_users' => [0, 1, 0, 0, 1],
            'repeat_order' => [1, 0, 0, 0, 0],
        ];

        foreach ($expected as $metric => $values) {
            $this->weeklyTrend($metric)->assertJsonPath('data.datasets.0.data', $values);
        }

        $this->weeklyTrend('conversion')->assertJsonPath('data.metric.unit', 'percent');
        $this->weeklyTrend('new_users')->assertJsonPath('data.metric.label', 'User Baru');
    }

    public function test_all_platforms_are_included_when_platform_filter_is_empty(): void
    {
        $this->seedTrendData();

        $this->weeklyTrend('revenue', null)
            ->assertJsonPath('data.filter.platform', null)
            ->assertJsonPath('data.datasets.0.data', [370000, 300000, 50000, 0, 400000]);
    }

    public function test_daily_period_returns_one_point_per_day_including_empty_days(): void
    {
        $this->seedTrendData();

        $response = $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-a&metric=revenue&period=daily')
            ->assertOk()
            ->assertJsonCount(30, 'data.labels')
            ->assertJsonPath('data.labels.0', '01 Sep 2026')
            ->assertJsonPath('data.labels.29', '30 Sep 2026');

        $data = $response->json('data.datasets.0.data');
        $this->assertSame([0, 200000, 0, 0, 100000], array_slice($data, 0, 5));
        $this->assertSame(400000, $data[29]);
    }

    public function test_monthly_buckets_are_cut_to_the_selected_range(): void
    {
        $this->seedTrendData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-08-15&date_to=2026-10-10&metric=transactions&period=monthly')
            ->assertOk()
            ->assertJsonPath('data.labels', ['Aug 2026', 'Sep 2026', 'Oct 2026'])
            ->assertJsonPath('data.datasets.0.data', [0, 6, 0])
            ->assertJsonPath('data.rows.0.period_start', '2026-08-15')
            ->assertJsonPath('data.rows.0.period_end', '2026-08-31')
            ->assertJsonPath('data.rows.2.period_start', '2026-10-01')
            ->assertJsonPath('data.rows.2.period_end', '2026-10-10');
    }

    public function test_weekly_labels_crossing_months_show_both_months(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-08-28&date_to=2026-09-03&metric=revenue&period=weekly')
            ->assertOk()
            ->assertJsonPath('data.labels', ['28 - 30 Aug 2026', '31 Aug - 03 Sep 2026']);
    }

    public function test_empty_range_returns_zero_points_and_informative_message(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-03&metric=conversion&period=daily')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.datasets.0.data', [null, null, null]);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-03&metric=transactions&period=daily')
            ->assertJsonPath('data.datasets.0.data', [0, 0, 0]);
    }
}
