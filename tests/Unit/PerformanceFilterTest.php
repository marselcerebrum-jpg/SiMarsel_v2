<?php

namespace Tests\Unit;

use App\Enums\TrendPeriod;
use App\Support\PerformanceFilter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PerformanceFilterTest extends TestCase
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{0: string, 1: string}>
     */
    private function dates(array $ranges): array
    {
        return array_map(fn (array $range) => [$range[0]->toDateString(), $range[1]->toDateString()], $ranges);
    }

    public function test_current_month_runs_until_today_and_compares_with_the_same_number_of_days_before(): void
    {
        $filter = PerformanceFilter::currentMonth(today: CarbonImmutable::create(2026, 10, 16, 13, 45));

        $this->assertSame(PerformanceFilter::MODE_CURRENT_MONTH, $filter->mode);
        $this->assertSame([['2026-10-01', '2026-10-16']], $this->dates($filter->ranges));
        $this->assertSame([['2026-09-15', '2026-09-30']], $this->dates($filter->previousRanges));
        $this->assertSame(TrendPeriod::Daily, $filter->defaultPeriod);
    }

    public function test_date_range_compares_with_the_same_number_of_days_before(): void
    {
        $filter = PerformanceFilter::dateRange(CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30));

        $this->assertSame([['2026-09-01', '2026-09-30']], $this->dates($filter->ranges));
        $this->assertSame([['2026-08-02', '2026-08-31']], $this->dates($filter->previousRanges));
    }

    public function test_month_covers_the_whole_month_and_compares_with_the_previous_month(): void
    {
        $filter = PerformanceFilter::month(2026, 3);

        $this->assertSame([['2026-03-01', '2026-03-31']], $this->dates($filter->ranges));
        $this->assertSame([['2026-02-01', '2026-02-28']], $this->dates($filter->previousRanges));
        $this->assertSame(TrendPeriod::Daily, $filter->defaultPeriod);
        $this->assertSame(31, $filter->days());
    }

    public function test_january_compares_with_december_of_the_year_before(): void
    {
        $filter = PerformanceFilter::month(2026, 1);

        $this->assertSame([['2025-12-01', '2025-12-31']], $this->dates($filter->previousRanges));
    }

    public function test_years_keep_one_range_per_year_and_compare_each_with_the_year_before(): void
    {
        $filter = PerformanceFilter::years([2026, 2024]);

        $this->assertSame([2024, 2026], $filter->years);
        $this->assertSame([['2024-01-01', '2024-12-31'], ['2026-01-01', '2026-12-31']], $this->dates($filter->ranges));
        $this->assertSame([['2023-01-01', '2023-12-31'], ['2025-01-01', '2025-12-31']], $this->dates($filter->previousRanges));
        $this->assertSame(TrendPeriod::Monthly, $filter->defaultPeriod);
        $this->assertTrue($filter->comparesYears());
    }

    public function test_consecutive_previous_years_are_merged_into_one_range(): void
    {
        $filter = PerformanceFilter::years([2025, 2026]);

        $this->assertSame([['2024-01-01', '2025-12-31']], $this->dates($filter->previousRanges));
    }

    public function test_one_year_is_not_a_year_comparison(): void
    {
        $this->assertFalse(PerformanceFilter::years([2026])->comparesYears());
    }

    public function test_year_range_compares_with_the_same_number_of_years_before(): void
    {
        $filter = PerformanceFilter::yearRange(2024, 2026);

        $this->assertSame([2024, 2025, 2026], $filter->years);
        $this->assertSame([['2024-01-01', '2026-12-31']], $this->dates($filter->ranges));
        $this->assertSame([['2021-01-01', '2023-12-31']], $this->dates($filter->previousRanges));
        $this->assertFalse($filter->comparesYears());
    }

    public function test_previous_uses_the_comparison_ranges_and_keeps_the_platforms(): void
    {
        $previous = PerformanceFilter::month(2026, 3, ['app-a'])->previous();

        $this->assertSame([['2026-02-01', '2026-02-28']], $this->dates($previous->ranges));
        $this->assertSame(['app-a'], $previous->platforms);
    }

    public function test_to_array_describes_the_active_filter(): void
    {
        $this->assertSame([
            'mode' => PerformanceFilter::MODE_YEARS,
            'date_from' => '2024-01-01',
            'date_to' => '2026-12-31',
            'platform' => null,
            'platforms' => ['app-a', 'app-b'],
            'years' => [2024, 2026],
            'month' => null,
            'ranges' => [
                ['date_from' => '2024-01-01', 'date_to' => '2024-12-31'],
                ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'],
            ],
        ], PerformanceFilter::years([2024, 2026], ['app-a', 'app-b'])->toArray());

        $this->assertSame('app-a', PerformanceFilter::month(2026, 9, ['app-a'])->toArray()['platform']);
    }
}
