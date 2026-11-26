<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\PeriodResolverService;
use App\Services\PlatformContributionService;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodSelection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEAM-299 (BE-01): per-platform created-cohort conversion and status breakdown, paid-date success payments,
 * revenue, the comparison revenue, and the contribution share of the selected platforms.
 */
class PlatformTransactionMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
    }

    /**
     * A complete transactions snapshot for one exact date range; the period resolver matches ranges exactly.
     */
    private function coverage(string $from, string $to): void
    {
        foreach ([$this->platformA, $this->platformB] as $platform) {
            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => $from,
                'date_to' => $to,
                'cutoff_at' => '2026-11-11 00:00:00',
                'is_complete' => true,
                'payload' => [],
                'recorded_at' => '2026-11-11 00:00:00',
            ]);
        }
    }

    public function test_ac01_conversion_is_60_percent_and_the_status_breakdown_adds_up_to_100(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        for ($i = 0; $i < 40; $i++) {
            $this->transaction($this->platformA, '2026-11-08 10:00:00', null, 0, MarkazTransactionStatus::Pending);
        }

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10')['platforms'][0];

        $this->assertSame(100, $row['total_transactions']);
        $this->assertSame(60, $row['created_success_transactions']);
        $this->assertSame(60.0, $row['conversion_percentage']);
        $this->assertFalse($row['conversion_is_provisional']);
        $this->assertSame(100, array_sum(array_column($row['status_breakdown'], 'count')));
        $this->assertSame(
            ['Success', 'Pending', 'Tidak Berhasil', 'Klaim Kupon', 'Status belum tersedia'],
            array_column($row['status_breakdown'], 'label'),
        );
    }

    public function test_ac02_contribution_uses_every_selected_platform_as_the_divisor(): void
    {
        for ($i = 0; $i < 65; $i++) {
            $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        for ($i = 0; $i < 195; $i++) {
            $this->transaction($this->platformB, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');

        $this->assertSame(260, $metrics['totals']['success_payments']);
        $this->assertSame(['app-b', 'app-a'], array_column($metrics['platforms'], 'slug'));
        $this->assertSame(75.0, $metrics['platforms'][0]['contribution_percentage']);
        $this->assertSame(25.0, $metrics['platforms'][1]['contribution_percentage']);
        $this->assertSame([1, 2], array_column($metrics['platforms'], 'rank'));
    }

    public function test_ac03_group_members_count_once_and_claims_stay_out_of_contribution(): void
    {
        $buyer = $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 300000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 0, MarkazTransactionStatus::Success, refId: $buyer->markaz_transaction_id);
        $this->transaction($this->platformA, '2026-11-08 09:30:00', '2026-11-08 09:30:00', 0, MarkazTransactionStatus::Success, claim: true);
        $this->transaction($this->platformB, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 100000);
        $this->transaction($this->platformB, '2026-11-08 10:00:00', null, 0, MarkazTransactionStatus::Pending);

        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');
        $a = $metrics['platforms'][0];
        $b = $metrics['platforms'][1];

        $this->assertSame('app-a', $a['slug']);
        $this->assertSame(50.0, $a['contribution_percentage']);
        $this->assertSame(50.0, $b['contribution_percentage']);
        $this->assertSame(300000.0, $a['revenue']);
        $this->assertSame(2, $a['total_transactions']);
        $this->assertSame(2, $b['total_transactions']);
        $this->assertSame(100.0, $a['conversion_percentage']);
        $this->assertSame(50.0, $b['conversion_percentage']);
        $this->assertFalse($b['conversion_is_provisional']);
    }

    public function test_zero_divisor_gives_null_contribution_and_conversion_not_zero(): void
    {
        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');

        $this->assertSame(0, $metrics['totals']['success_payments']);
        $this->assertTrue($metrics['is_empty']);
        $this->assertNull($metrics['platforms'][0]['contribution_percentage']);
        $this->assertNull($metrics['platforms'][0]['conversion_percentage']);
    }

    public function test_unknown_status_is_counted_in_conversion_and_marked_provisional(): void
    {
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', null, 0, null);

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10')['platforms'][0];

        $this->assertSame(50.0, $row['conversion_percentage']);
        $this->assertTrue($row['conversion_is_provisional']);
        $this->assertSame(1, $row['unknown_status_count']);
    }

    public function test_revenue_comparison_uses_the_previous_period_range(): void
    {
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 500000);
        $this->transaction($this->platformA, '2026-11-06 09:00:00', '2026-11-06 09:10:00', 200000);

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10', PeriodComparison::PreviousPeriod)['platforms'][0];

        $this->assertSame(500000.0, $row['revenue']);
        $this->assertSame(200000.0, $row['revenue_comparison']);
    }

    public function test_equivalent_hour_comparison_keeps_the_value_recorded_at_its_cutoff_after_a_status_change(): void
    {
        $this->transaction($this->platformA, '2026-11-09 09:00:00', '2026-11-09 10:00:00', 300000);
        $this->recordEquivalentHour(revenueOfPlatformA: 300000.0);

        // The status changes after the 14.00 snapshot was recorded; the comparison must not follow it.
        Transaction::query()->where('is_claim_coupon', false)->update(['source_status' => MarkazTransactionStatus::Failed->value]);

        $metrics = $this->equivalentHourMetrics();
        $this->assertSame('at_cutoff', $metrics['period']['comparison']['state_basis']);

        $this->assertSame(300000.0, $metrics['platforms'][0]['revenue_comparison']);
    }

    public function test_equivalent_hour_comparison_is_unavailable_without_a_snapshot_at_its_cutoff(): void
    {
        $this->transaction($this->platformA, '2026-11-09 09:00:00', '2026-11-09 10:00:00', 300000);
        $this->recordEquivalentHour(revenueOfPlatformA: 300000.0, withComparisonSnapshot: false);

        $metrics = $this->equivalentHourMetrics();

        // The latest state still has 300.000 on 9 November, but a missing snapshot must not be rebuilt from it.
        $this->assertSame('unavailable', $metrics['period']['comparison']['availability']);
        $this->assertNull($metrics['platforms'][0]['revenue_comparison']);
        $this->assertNull($metrics['totals']['revenue_comparison']);
    }

    public function test_equivalent_hour_comparison_is_unavailable_when_the_snapshot_is_incomplete(): void
    {
        $this->transaction($this->platformA, '2026-11-09 09:00:00', '2026-11-09 10:00:00', 300000);
        $this->recordEquivalentHour(revenueOfPlatformA: 300000.0, comparisonIsComplete: false);

        $metrics = $this->equivalentHourMetrics();

        $this->assertSame('unavailable', $metrics['period']['comparison']['availability']);
        $this->assertNull($metrics['platforms'][0]['revenue_comparison']);
    }

    /**
     * Complete snapshots of both platforms at 10 November 14.00 (active side) and at 9 November 14.00 (comparison side).
     * The comparison snapshots carry the revenue recorded at that hour; platform B records 0.
     */
    private function recordEquivalentHour(float $revenueOfPlatformA, bool $withComparisonSnapshot = true, bool $comparisonIsComplete = true): void
    {
        foreach ([$this->platformA, $this->platformB] as $platform) {
            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => '2026-11-10',
                'date_to' => '2026-11-10',
                'cutoff_at' => '2026-11-10 14:00:00',
                'is_complete' => true,
                'payload' => [],
                'recorded_at' => '2026-11-10 14:00:00',
            ]);

            if (! $withComparisonSnapshot) {
                continue;
            }

            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => '2026-11-09',
                'date_to' => '2026-11-09',
                'cutoff_at' => '2026-11-09 14:00:00',
                'is_complete' => $comparisonIsComplete,
                'payload' => ['revenue' => $platform->is($this->platformA) ? $revenueOfPlatformA : 0.0],
                'recorded_at' => '2026-11-09 14:00:00',
            ]);
        }
    }

    /**
     * Active side: 10 November up to 14.00 (partial). Comparison: the equivalent hour of 9 November.
     *
     * @return array<string, mixed>
     */
    private function equivalentHourMetrics(): array
    {
        $period = app(PeriodResolverService::class)->resolve(new PeriodSelection(
            preset: PeriodPreset::DateRange,
            comparison: PeriodComparison::Auto,
            platforms: ['app-a', 'app-b'],
            dateFrom: CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            dateTo: CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
        ));

        return app(PlatformContributionService::class)->transactionMetrics(PerformanceFilter::fromResolvedPeriod($period));
    }

    public function test_multi_day_equivalent_hour_comparison_keeps_every_day_at_the_recorded_cutoff(): void
    {
        $this->transaction($this->platformA, '2026-11-07 09:00:00', '2026-11-07 10:00:00', 300000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 10:00:00', 200000);
        $this->recordMultiDayEquivalentHour(revenueOfPlatformA: 500000.0);

        // 7 November is the day before the last comparison day: its status changes after the snapshot was recorded.
        Transaction::query()->whereDate('paid_date', '2026-11-07')->update(['source_status' => MarkazTransactionStatus::Failed->value]);

        $metrics = $this->multiDayEquivalentHourMetrics();

        $this->assertSame('at_cutoff', $metrics['period']['comparison']['state_basis']);
        $this->assertSame('2026-11-07', $metrics['period']['comparison']['date_from']);
        $this->assertSame('2026-11-08', $metrics['period']['comparison']['date_to']);

        // The recorded range value, not the latest 200.000 (8 November only) nor the changed 7 November.
        $this->assertSame(500000.0, $metrics['platforms'][0]['revenue_comparison']);
    }

    public function test_multi_day_equivalent_hour_comparison_is_unavailable_without_its_range_snapshot(): void
    {
        $this->transaction($this->platformA, '2026-11-07 09:00:00', '2026-11-07 10:00:00', 300000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 10:00:00', 200000);
        $this->recordMultiDayEquivalentHour(revenueOfPlatformA: 500000.0, withComparisonSnapshot: false);

        $metrics = $this->multiDayEquivalentHourMetrics();

        $this->assertSame('unavailable', $metrics['period']['comparison']['availability']);
        $this->assertNull($metrics['platforms'][0]['revenue_comparison']);
        $this->assertNull($metrics['totals']['revenue_comparison']);
    }

    public function test_multi_day_equivalent_hour_comparison_is_unavailable_when_the_range_snapshot_is_incomplete(): void
    {
        $this->transaction($this->platformA, '2026-11-07 09:00:00', '2026-11-07 10:00:00', 300000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 10:00:00', 200000);
        $this->recordMultiDayEquivalentHour(revenueOfPlatformA: 500000.0, comparisonIsComplete: false);

        $metrics = $this->multiDayEquivalentHourMetrics();

        $this->assertSame('unavailable', $metrics['period']['comparison']['availability']);
        $this->assertNull($metrics['platforms'][0]['revenue_comparison']);
    }

    /**
     * Active side: 9-10 November up to 10 November 14.00 (partial). Comparison: 7-8 November up to 8 November 14.00,
     * recorded as one snapshot for the whole range. Platform A records $revenueOfPlatformA; platform B records 0.
     */
    private function recordMultiDayEquivalentHour(float $revenueOfPlatformA, bool $withComparisonSnapshot = true, bool $comparisonIsComplete = true): void
    {
        foreach ([$this->platformA, $this->platformB] as $platform) {
            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => '2026-11-09',
                'date_to' => '2026-11-10',
                'cutoff_at' => '2026-11-10 14:00:00',
                'is_complete' => true,
                'payload' => [],
                'recorded_at' => '2026-11-10 14:00:00',
            ]);

            if (! $withComparisonSnapshot) {
                continue;
            }

            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => '2026-11-07',
                'date_to' => '2026-11-08',
                'cutoff_at' => '2026-11-08 14:00:00',
                'is_complete' => $comparisonIsComplete,
                'payload' => ['revenue' => $platform->is($this->platformA) ? $revenueOfPlatformA : 0.0],
                'recorded_at' => '2026-11-08 14:00:00',
            ]);
        }
    }

    /**
     * Two-day active period 9-10 November, cutoff at 10 November 14.00.
     *
     * @return array<string, mixed>
     */
    private function multiDayEquivalentHourMetrics(): array
    {
        $period = app(PeriodResolverService::class)->resolve(new PeriodSelection(
            preset: PeriodPreset::DateRange,
            comparison: PeriodComparison::Auto,
            platforms: ['app-a', 'app-b'],
            dateFrom: CarbonImmutable::parse('2026-11-09', 'Asia/Jakarta'),
            dateTo: CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
        ));

        return app(PlatformContributionService::class)->transactionMetrics(PerformanceFilter::fromResolvedPeriod($period));
    }

    private function metrics(array $slugs, string $from, string $to, PeriodComparison $comparison = PeriodComparison::Auto): array
    {
        $this->coverage($from, $to);
        $this->coverage('2026-11-05', '2026-11-07');

        $period = app(PeriodResolverService::class)->resolve(new PeriodSelection(
            preset: PeriodPreset::DateRange,
            comparison: $comparison,
            platforms: $slugs,
            dateFrom: CarbonImmutable::parse($from, 'Asia/Jakarta'),
            dateTo: CarbonImmutable::parse($to, 'Asia/Jakarta'),
        ));

        return app(PlatformContributionService::class)->transactionMetrics(PerformanceFilter::fromResolvedPeriod($period));
    }

    private function transaction(
        Platform $platform,
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->for($platform)->create([
            'markaz_customer_id' => fake()->numberBetween(1, 999999),
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
