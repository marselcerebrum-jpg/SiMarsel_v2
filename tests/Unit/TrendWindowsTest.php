<?php

namespace Tests\Unit;

use App\Enums\DataAvailability;
use App\Enums\TransactionDateBasis;
use App\Enums\TrendPeriod;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TrendBuckets;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * FIX-06-BE-01: trend buckets with exclusive ends, comparison buckets with their own original times,
 * and hour-level aggregation windows.
 */
class TrendWindowsTest extends TestCase
{
    public function test_one_day_has_24_hourly_buckets_with_the_starting_hour_as_label(): void
    {
        $buckets = TrendBuckets::windows($this->at('2026-11-11'), $this->at('2026-11-12'), TrendPeriod::Hourly);

        $this->assertCount(24, $buckets);
        $this->assertSame('11 Nov 2026 00.00', $buckets[0]['label']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $buckets[14]['start']->toIso8601String());
        $this->assertSame('2026-11-11T15:00:00+07:00', $buckets[14]['end']->toIso8601String());
        $this->assertSame('2026-11-12T00:00:00+07:00', $buckets[23]['end']->toIso8601String());
    }

    public function test_weeks_run_monday_to_sunday_and_months_are_cut_to_the_range(): void
    {
        $weeks = TrendBuckets::windows($this->at('2026-08-13'), $this->at('2026-08-25'), TrendPeriod::Weekly);
        $months = TrendBuckets::windows($this->at('2026-01-15'), $this->at('2026-03-11'), TrendPeriod::Monthly);

        $this->assertSame(['13 - 16 Agu 2026', '17 - 23 Agu 2026', '24 Agu 2026'], array_column($weeks, 'label'));
        $this->assertSame(['Jan 2026', 'Feb 2026', 'Mar 2026'], array_column($months, 'label'));
        $this->assertSame('2026-01-15T00:00:00+07:00', $months[0]['start']->toIso8601String());
        $this->assertSame('2026-03-11T00:00:00+07:00', $months[2]['end']->toIso8601String());
    }

    public function test_comparison_buckets_take_the_same_length_from_the_comparison_start(): void
    {
        $active = TrendBuckets::windows($this->at('2026-08-13'), $this->at('2026-08-25'), TrendPeriod::Weekly);
        $comparison = TrendBuckets::comparisonWindows($active, $this->at('2026-05-15'), $this->at('2026-05-27'), TrendPeriod::Weekly);

        $this->assertSame('2026-05-15T00:00:00+07:00', $comparison[0]['start']->toIso8601String());
        $this->assertSame('2026-05-19T00:00:00+07:00', $comparison[0]['end']->toIso8601String());
        $this->assertSame('15 - 18 Mei 2026', $comparison[0]['label']);
        $this->assertSame('2026-05-26T00:00:00+07:00', $comparison[1]['end']->toIso8601String());
        $this->assertSame('2026-05-27T00:00:00+07:00', $comparison[2]['end']->toIso8601String());
    }

    public function test_positions_the_comparison_does_not_reach_have_no_comparison_bucket(): void
    {
        $active = TrendBuckets::windows($this->at('2026-03-01'), $this->at('2026-04-01'), TrendPeriod::Daily);
        $comparison = TrendBuckets::comparisonWindows($active, $this->at('2026-02-01'), $this->at('2026-03-01'), TrendPeriod::Daily);

        $this->assertCount(31, $comparison);
        $this->assertSame('28 Feb 2026', $comparison[27]['label']);
        $this->assertNull($comparison[28]);
        $this->assertNull($comparison[30]);
    }

    public function test_comparison_months_pair_calendar_months(): void
    {
        $active = TrendBuckets::windows($this->at('2026-01-01'), $this->at('2026-11-12'), TrendPeriod::Monthly);
        $comparison = TrendBuckets::comparisonWindows($active, $this->at('2025-01-01'), $this->at('2025-11-12'), TrendPeriod::Monthly);

        $this->assertSame('Feb 2025', $comparison[1]['label']);
        $this->assertSame('2025-03-01T00:00:00+07:00', $comparison[1]['end']->toIso8601String());
        $this->assertSame('2025-11-12T00:00:00+07:00', $comparison[10]['end']->toIso8601String());
    }

    public function test_an_hour_window_is_complete_partial_or_unavailable_by_the_cutoff(): void
    {
        $window = fn (?string $completeUntil) => AggregationFilter::forWindow(
            [1],
            '2026-11-11T13:00:00+07:00',
            '2026-11-11T14:00:00+07:00',
            TransactionDateBasis::Paid,
            $completeUntil,
        );

        $this->assertSame(DataAvailability::Complete, $window('2026-11-11T14:00:00+07:00')->availability());
        $this->assertSame('2026-11-11T14:00:00+07:00', $window('2026-11-11T16:00:00+07:00')->cutoff()->toIso8601String());
        $this->assertSame(DataAvailability::Partial, $window('2026-11-11T13:30:00+07:00')->availability());
        $this->assertSame(DataAvailability::Unavailable, $window('2026-11-11T13:00:00+07:00')->availability());
        $this->assertSame(DataAvailability::Unavailable, $window(null)->availability());
        $this->assertSame('2026-11-11', $window(null)->dateTo->toDateString());
    }

    public function test_a_window_must_end_after_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AggregationFilter::forWindow([1], '2026-11-11T14:00:00+07:00', '2026-11-11T14:00:00+07:00', TransactionDateBasis::Paid, null);
    }

    private function at(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Jakarta');
    }
}
