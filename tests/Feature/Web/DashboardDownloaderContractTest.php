<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Downloader;
use App\Models\Platform;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Downloader chart and table (FIX-08-FE-01) read `GET /api/performance/trend/downloaders` with the query the
 * global filter builds plus `granularity`. This scenario is the one in resources/js/pages/performance/fixtures/downloader.js.
 */
class DashboardDownloaderContractTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/trend/downloaders?platforms%5B%5D=app-a&';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:00:00+07:00'));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        foreach ([1 => 2, 3, 3, 5, 8, 12, 20, 31, 42, 50, 58, 66, 73, 88] as $hour => $total) {
            $this->record('2026-11-11', '2026-11-11', sprintf('2026-11-11 %02d:00:00', $hour), ['2026-11-11' => $total]);
        }
        // Hourly records of 10 Nov; 11.00 is never recorded.
        foreach ([1 => 0, 1, 1, 2, 4, 9, 16, 25, 38, 47, 12 => 60, 70, 80] as $hour => $total) {
            $this->record('2026-11-10', '2026-11-10', sprintf('2026-11-10 %02d:00:00', $hour), ['2026-11-10' => $total]);
        }

        $this->record('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->record('2026-11-09', '2026-11-09', '2026-11-10 00:00:00');
        $this->record('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->record('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');

        $totals = [
            '2026-10-28' => 70, '2026-10-29' => 72, '2026-10-30' => 75, '2026-10-31' => 80, '2026-11-01' => 60, '2026-11-02' => 65, '2026-11-03' => 68,
            '2026-11-04' => 84, '2026-11-05' => 91, '2026-11-06' => 98, '2026-11-07' => 44, '2026-11-08' => 51, '2026-11-09' => 90, '2026-11-11' => 88,
        ];
        foreach ($totals as $date => $total) {
            Downloader::factory()->for($this->platform)->create(['date' => $date, 'total' => $total]);
        }
    }

    public function test_the_trend_returns_every_field_the_chart_and_table_read(): void
    {
        $this->revisedYesterday();

        $trend = $this->trend('preset=today')
            ->assertJsonStructure(['data' => [
                'filter' => ['period' => ['active' => ['cutoff_at']]],
                'granularity',
                'granularities',
                'series' => [['key', 'label', 'side']],
                'buckets' => [['index', 'active' => ['start_at', 'end_at', 'availability', 'as_of', 'value'], 'comparison', 'change' => ['change_percentage', 'is_comparable']]],
            ]])
            ->json('data');

        $this->assertSame('hourly', $trend['granularity']);
        $this->assertSame(['hourly', 'daily', 'weekly', 'monthly'], $trend['granularities']);
        $this->assertSame([['Downloader', 'active'], ['Downloader Pembanding', 'comparison']], array_map(fn (array $series) => [$series['label'], $series['side']], $trend['series']));
        $this->assertSame('2026-11-11T14:00:00+07:00', $trend['filter']['period']['active']['cutoff_at']);
        $this->assertCount(24, $trend['buckets']);
    }

    public function test_the_hourly_fixture_scenario_matches_the_endpoint(): void
    {
        $this->revisedYesterday();
        $buckets = $this->trend('preset=today')->json('data.buckets');

        $this->assertSame(['2026-11-11T14:00:00+07:00', 88, '2026-11-10T14:00:00+07:00', 80], [$buckets[13]['active']['as_of'], $buckets[13]['active']['value'], $buckets[13]['comparison']['as_of'], $buckets[13]['comparison']['value']]);
        $this->assertEquals(['change_percentage' => 10, 'trend' => 'up', 'is_comparable' => true], $buckets[13]['change']);

        $this->assertSame(0, $buckets[0]['comparison']['value']);
        $this->assertEquals(['change_percentage' => null, 'trend' => 'up', 'is_comparable' => true], $buckets[0]['change']);

        $this->assertSame(58, $buckets[10]['active']['value']);
        $this->assertNull($buckets[10]['comparison']['value']);
        $this->assertFalse($buckets[10]['change']['is_comparable']);

        $this->assertNull($buckets[14]['active']['value']);
    }

    public function test_a_revised_daily_total_replaces_the_full_period_while_the_recorded_hour_keeps_its_value(): void
    {
        $yesterday = Downloader::factory()->for($this->platform)->create(['date' => '2026-11-10', 'total' => 100]);
        $this->assertSame(100, $this->trend('preset=yesterday&granularity=daily')->json('data.buckets.0.active.value'));

        $yesterday->update(['total' => 99]);
        $this->assertSame(99, $this->trend('preset=yesterday&granularity=daily')->json('data.buckets.0.active.value'));

        $hourly = $this->trend('preset=yesterday')->json('data.buckets');
        $this->assertSame([80, 99, 90], [$hourly[13]['active']['value'], $hourly[23]['active']['value'], $hourly[23]['comparison']['value']]);
        $this->assertNull($hourly[13]['comparison']['value'], '9 Nov has its daily total only');
    }

    public function test_a_missing_comparison_record_at_the_cutoff_leaves_the_comparison_empty(): void
    {
        $this->revisedYesterday();
        DashboardSnapshot::where('cutoff_at', '2026-11-10 14:00:00')->delete();

        $bucket = $this->trend('preset=today')->json('data.buckets.13');

        $this->assertSame(88, $bucket['active']['value']);
        $this->assertNull($bucket['comparison']['value']);
        $this->assertFalse($bucket['change']['is_comparable']);
    }

    public function test_the_rows_add_up_to_the_totals_platform_and_summary_of_the_same_scope(): void
    {
        $this->revisedYesterday();
        $trend = $this->trend('preset=last_7_days')->json('data');

        $this->assertSame([84, 91, 98, 44, 51, 90, 99], array_column(array_column($trend['buckets'], 'active'), 'value'));
        $this->assertSame(557, $trend['totals']['active']['value']);
        $this->assertSame(557, $trend['platforms'][0]['value']);

        $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?platforms%5B%5D=app-a&preset=last_7_days')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_downloaders.value', 557);
    }

    private function revisedYesterday(): void
    {
        Downloader::factory()->for($this->platform)->create(['date' => '2026-11-10', 'total' => 100])->update(['total' => 99]);
    }

    private function trend(string $query): TestResponse
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk();
    }

    /**
     * @param  array<string, int>  $daily  Cumulative total per covered date at the cutoff.
     */
    private function record(string $from, string $to, string $cutoffAt, array $daily = []): void
    {
        DashboardSnapshot::factory()->for($this->platform)->downloaders($daily)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'recorded_at' => $cutoffAt,
        ]);
    }
}
