<?php

namespace Tests\Feature\Web;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The combined trend chart (FIX-06-FE-01) reads `GET /api/performance/trend/sales` with the query the global
 * filter builds plus `granularity`. This scenario is the one in resources/js/pages/performance/fixtures/trend.js.
 */
class DashboardTrendContractTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/trend/sales';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:00:00+07:00'));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->record('2026-11-11', '2026-11-11 14:00:00');

        // Hourly comparison records of 10 Nov; 11.00 is never recorded.
        $records = [
            1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0],
            8 => [150000, 1, 1], 9 => [330000, 2, 3], 10 => [330000, 2, 4], 12 => [530000, 3, 5], 13 => [530000, 3, 5], 14 => [650000, 4, 6],
        ];
        foreach ($records as $hour => [$revenue, $payments, $checkouts]) {
            $this->record('2026-11-10', sprintf('2026-11-10 %02d:00:00', $hour), [
                'revenue' => $revenue, 'success_payments' => $payments, 'total_checkouts' => $checkouts,
            ]);
        }

        $this->transaction('2026-11-11 08:15:00', '2026-11-11 08:20:00', 180000);
        $this->transaction('2026-11-11 08:30:00', '2026-11-11 08:45:00', 180000);
        $this->transaction('2026-11-11 09:10:00', null, 150000, MarkazTransactionStatus::Pending);
        $this->transaction('2026-11-11 09:55:00', '2026-11-11 10:00:00', 0, claim: true);
    }

    public function test_the_trend_returns_every_field_the_chart_reads(): void
    {
        $trend = $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms%5B%5D=app-a&preset=today')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'granularity',
                'granularities',
                'series' => [['key', 'label', 'side', 'value', 'unit', 'axis', 'visible']],
                'buckets' => [['active' => ['start_at', 'end_at', 'availability', 'as_of', 'values'], 'comparison']],
            ]])
            ->json('data');

        $this->assertSame('hourly', $trend['granularity']);
        $this->assertSame(['hourly', 'daily', 'weekly', 'monthly'], $trend['granularities']);
        $this->assertSame(
            [
                ['revenue', 'Omzet', 'active', 'currency', true],
                ['comparison_revenue', 'Omzet Pembanding', 'comparison', 'currency', true],
                ['success_payments', 'Transaksi Berhasil', 'active', 'count', true],
                ['total_checkouts', 'Total Transaksi', 'active', 'count', false],
            ],
            array_map(fn (array $series) => [$series['key'], $series['label'], $series['side'], $series['axis'], $series['visible']], $trend['series']),
        );
        $this->assertCount(24, $trend['buckets']);
    }

    public function test_the_fixture_scenario_matches_the_endpoint(): void
    {
        $buckets = $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms%5B%5D=app-a&preset=today')
            ->assertOk()
            ->json('data.buckets');

        $this->assertEquals(['revenue' => 360000, 'success_payments' => 2, 'total_checkouts' => 2], $buckets[8]['active']['values']);
        $this->assertSame(['2026-11-10T08:00:00+07:00', '2026-11-10T09:00:00+07:00'], [$buckets[8]['comparison']['start_at'], $buckets[8]['comparison']['end_at']]);
        $this->assertEquals(180000, $buckets[8]['comparison']['values']['revenue']);
        $this->assertEquals(2, $buckets[9]['active']['values']['total_checkouts']);

        foreach ([10, 11] as $missing) {
            $this->assertSame('unavailable', $buckets[$missing]['comparison']['availability']);
            $this->assertNull($buckets[$missing]['comparison']['values']);
        }

        $this->assertSame('unavailable', $buckets[14]['active']['availability']);
        $this->assertNull($buckets[14]['active']['values']);
        $this->assertNull($buckets[14]['comparison']['values']);
    }

    public function test_another_granularity_regroups_and_a_range_too_long_for_it_is_rejected(): void
    {
        $daily = $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms%5B%5D=app-a&preset=today&granularity=daily')
            ->assertOk()
            ->json('data');

        $this->assertSame('daily', $daily['granularity']);
        $this->assertSame(['partial', '2026-11-11T14:00:00+07:00'], [$daily['buckets'][0]['active']['availability'], $daily['buckets'][0]['active']['as_of']]);
        $this->assertEquals(360000, $daily['buckets'][0]['active']['values']['revenue']);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?preset=last_90_days')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'weekly')
            ->assertJsonPath('data.granularities', ['daily', 'weekly', 'monthly']);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?preset=last_90_days&granularity=hourly')
            ->assertUnprocessable()
            ->assertJsonPath('errors.granularity.0', 'Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.');
    }

    /**
     * @param  array<string, int>  $payload
     */
    private function record(string $date, string $cutoffAt, array $payload = []): void
    {
        DashboardSnapshot::factory()->for($this->platform)->create([
            'date_from' => $date,
            'date_to' => $date,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => $payload,
            'recorded_at' => $cutoffAt,
        ]);
    }

    private function transaction(
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
    ): void {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        Transaction::factory()->for($this->platform)->create([
            'markaz_customer_id' => fake()->numberBetween(1, 999999),
            'markaz_ref_id' => null,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
        ]);
    }
}
