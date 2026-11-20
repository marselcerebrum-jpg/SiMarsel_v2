<?php

namespace Tests\Unit;

use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Services\PeriodResolverService;
use App\Support\Period\PeriodSelection;
use App\Support\Period\ResolvedPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryPeriodCoverage;
use Tests\TestCase;

class PeriodResolverServiceTest extends TestCase
{
    private const NOW = '2026-11-11T14:00:00+07:00';

    private function resolve(InMemoryPeriodCoverage $coverage, PeriodSelection $selection, string $now = self::NOW): ResolvedPeriod
    {
        return (new PeriodResolverService($coverage))->resolve($selection, CarbonImmutable::parse($now));
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Jakarta');
    }

    private function dateRange(string $from, string $to, PeriodComparison $comparison = PeriodComparison::Auto, ?string $comparisonFrom = null, ?string $comparisonTo = null): PeriodSelection
    {
        return new PeriodSelection(
            PeriodPreset::DateRange,
            $comparison,
            dateFrom: $this->date($from),
            dateTo: $this->date($to),
            comparisonFrom: $comparisonFrom ? $this->date($comparisonFrom) : null,
            comparisonTo: $comparisonTo ? $this->date($comparisonTo) : null,
        );
    }

    /**
     * Preset, active dates, active limit, comparison dates, comparison limit, and state of the comparison at 11 Nov 14.00.
     *
     * @return array<string, array{0: PeriodPreset, 1: array{0: string, 1: string}, 2: string, 3: array{0: string, 1: string}, 4: string, 5: string}>
     */
    public static function presets(): array
    {
        return [
            'hari ini' => [PeriodPreset::Today, ['2026-11-11', '2026-11-11'], '2026-11-11T14:00:00+07:00', ['2026-11-10', '2026-11-10'], '2026-11-10T14:00:00+07:00', 'at_cutoff'],
            'kemarin' => [PeriodPreset::Yesterday, ['2026-11-10', '2026-11-10'], '2026-11-11T00:00:00+07:00', ['2026-11-09', '2026-11-09'], '2026-11-10T00:00:00+07:00', 'latest'],
            '7 hari' => [PeriodPreset::Last7Days, ['2026-11-04', '2026-11-10'], '2026-11-11T00:00:00+07:00', ['2026-10-28', '2026-11-03'], '2026-11-04T00:00:00+07:00', 'latest'],
            '30 hari' => [PeriodPreset::Last30Days, ['2026-10-12', '2026-11-10'], '2026-11-11T00:00:00+07:00', ['2026-09-12', '2026-10-11'], '2026-10-12T00:00:00+07:00', 'latest'],
            '90 hari' => [PeriodPreset::Last90Days, ['2026-08-13', '2026-11-10'], '2026-11-11T00:00:00+07:00', ['2026-05-15', '2026-08-12'], '2026-08-13T00:00:00+07:00', 'latest'],
            'bulan ini' => [PeriodPreset::ThisMonth, ['2026-11-01', '2026-11-11'], '2026-11-11T14:00:00+07:00', ['2026-10-01', '2026-10-11'], '2026-10-11T14:00:00+07:00', 'at_cutoff'],
            'bulan lalu' => [PeriodPreset::LastMonth, ['2026-10-01', '2026-10-31'], '2026-11-01T00:00:00+07:00', ['2026-09-01', '2026-09-30'], '2026-10-01T00:00:00+07:00', 'latest'],
            'tahun lalu' => [PeriodPreset::LastYear, ['2026-01-01', '2026-11-11'], '2026-11-11T14:00:00+07:00', ['2025-01-01', '2025-11-11'], '2025-11-11T14:00:00+07:00', 'at_cutoff'],
        ];
    }

    #[DataProvider('presets')]
    public function test_every_preset_resolves_its_ranges_cutoff_and_state(
        PeriodPreset $preset,
        array $activeDates,
        string $activeLimit,
        array $comparisonDates,
        string $comparisonLimit,
        string $state,
    ): void {
        $coverage = (new InMemoryPeriodCoverage)
            ->record($activeDates[0], $activeDates[1], $activeLimit)
            ->record($comparisonDates[0], $comparisonDates[1], $comparisonLimit);

        $period = $this->resolve($coverage, new PeriodSelection($preset))->toArray();

        $this->assertSame($activeDates, [$period['active']['date_from'], $period['active']['date_to']]);
        $this->assertSame($activeLimit, $period['active']['cutoff_at']);
        $this->assertSame($comparisonDates, [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertSame($comparisonLimit, $period['comparison']['cutoff_at']);
        $this->assertSame($state, $period['comparison']['state_basis']);
        $this->assertFalse($period['change_basis']['is_trimmed']);
    }

    public function test_date_range_ending_today_keeps_the_same_hour_and_counts_both_ends(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-11-05', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-10-29', '2026-11-04', '2026-11-04T14:00:00+07:00');

        $period = $this->resolve($coverage, $this->dateRange('2026-11-05', '2026-11-11'))->toArray();

        $this->assertSame(7, $period['active']['days']);
        $this->assertSame(['2026-10-29', '2026-11-04'], [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertSame('2026-11-04T14:00:00+07:00', $period['comparison']['cutoff_at']);
        $this->assertSame('at_cutoff', $period['comparison']['state_basis']);
    }

    public function test_past_date_range_compares_the_same_number_of_full_days(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-10-05', '2026-10-11', '2026-10-12T00:00:00+07:00')
            ->record('2026-09-28', '2026-10-04', '2026-10-05T00:00:00+07:00');

        $period = $this->resolve($coverage, $this->dateRange('2026-10-05', '2026-10-11'))->toArray();

        $this->assertSame(['2026-09-28', '2026-10-04'], [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertSame('latest', $period['comparison']['state_basis']);
        $this->assertSame('complete', $period['comparison']['availability']);
    }

    public function test_ac01_this_month_on_31_march_keeps_the_active_total_and_trims_the_change(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-03-01', '2026-03-31', '2026-03-31T14:00:00+07:00')
            ->record('2026-02-01', '2026-02-28', '2026-03-01T00:00:00+07:00');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::ThisMonth), '2026-03-31T14:00:00+07:00')->toArray();

        $this->assertSame(['2026-03-01', '2026-03-31'], [$period['active']['date_from'], $period['active']['date_to']]);
        $this->assertSame('2026-03-31T14:00:00+07:00', $period['active']['cutoff_at']);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertTrue($period['change_basis']['is_trimmed']);
        $this->assertSame([['2026-03-01', '2026-03-28']], $this->basisDates($period['change_basis']['active']));
        $this->assertSame('2026-03-29T00:00:00+07:00', $period['change_basis']['active'][0]['end_at']);
        $this->assertSame([['2026-02-01', '2026-02-28']], $this->basisDates($period['change_basis']['comparison']));
    }

    public function test_dates_without_an_equivalent_in_the_middle_of_the_range_are_left_out_of_the_change(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-03-20', '2026-04-10', '2026-04-11T00:00:00+07:00')
            ->record('2026-02-20', '2026-03-10', '2026-03-11T00:00:00+07:00');

        $period = $this->resolve(
            $coverage,
            $this->dateRange('2026-03-20', '2026-04-10', PeriodComparison::SamePeriodLastMonth),
            '2026-04-15T10:00:00+07:00',
        )->toArray();

        $this->assertSame(22, $period['active']['days']);
        $this->assertSame(['2026-02-20', '2026-03-10'], [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertSame(19, $period['comparison']['days']);

        $this->assertTrue($period['change_basis']['is_trimmed']);
        $this->assertSame(19, $period['change_basis']['active_days']);
        $this->assertSame(19, $period['change_basis']['comparison_days']);
        $this->assertSame([['2026-03-20', '2026-03-28'], ['2026-04-01', '2026-04-10']], $this->basisDates($period['change_basis']['active']));
        $this->assertSame([['2026-02-20', '2026-03-10']], $this->basisDates($period['change_basis']['comparison']));
    }

    public function test_comparison_days_that_no_active_day_maps_to_are_left_out_of_the_change(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-02-28', '2026-04-01', '2026-04-02T00:00:00+07:00')
            ->record('2026-01-28', '2026-03-01', '2026-03-02T00:00:00+07:00');

        $period = $this->resolve(
            $coverage,
            $this->dateRange('2026-02-28', '2026-04-01', PeriodComparison::SamePeriodLastMonth),
            '2026-04-15T10:00:00+07:00',
        )->toArray();

        $this->assertSame(33, $period['comparison']['days']);
        $this->assertSame(30, $period['change_basis']['active_days']);
        $this->assertSame(30, $period['change_basis']['comparison_days']);
        $this->assertSame([['2026-02-28', '2026-03-28'], ['2026-04-01', '2026-04-01']], $this->basisDates($period['change_basis']['active']));
        $this->assertSame([['2026-01-28', '2026-01-28'], ['2026-02-01', '2026-03-01']], $this->basisDates($period['change_basis']['comparison']));
    }

    /**
     * @param  list<array{date_from: string, date_to: string}>  $ranges
     * @return list<array{0: string, 1: string}>
     */
    private function basisDates(array $ranges): array
    {
        return array_map(fn (array $range) => [$range['date_from'], $range['date_to']], $ranges);
    }

    public function test_leap_year_february_has_the_29th_as_an_equivalent_date(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2028-03-01', '2028-03-29', '2028-03-29T14:00:00+07:00')
            ->record('2028-02-01', '2028-02-29', '2028-02-29T14:00:00+07:00');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::ThisMonth), '2028-03-29T14:00:00+07:00')->toArray();

        $this->assertSame('2028-02-29T14:00:00+07:00', $period['comparison']['cutoff_at']);
        $this->assertFalse($period['change_basis']['is_trimmed']);
    }

    public function test_29_february_has_no_equivalent_date_last_year_so_the_change_stops_on_the_28th(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2028-01-01', '2028-02-29', '2028-02-29T14:00:00+07:00')
            ->record('2027-01-01', '2027-02-28', '2027-03-01T00:00:00+07:00');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::LastYear), '2028-02-29T14:00:00+07:00')->toArray();

        $this->assertSame('2028-02-29', $period['active']['date_to']);
        $this->assertSame('2027-02-28', $period['comparison']['date_to']);
        $this->assertSame('2028-02-28', $period['change_basis']['active'][0]['date_to']);
        $this->assertTrue($period['change_basis']['is_trimmed']);
    }

    public function test_automatic_last_month_compares_whole_months_of_different_lengths(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-03-01', '2026-03-31', '2026-04-01T00:00:00+07:00')
            ->record('2026-02-01', '2026-02-28', '2026-03-01T00:00:00+07:00');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::LastMonth), '2026-04-15T14:00:00+07:00');

        $this->assertSame(31, $period->active->days());
        $this->assertSame(28, $period->comparison->days());
        $this->assertTrue($period->durationsDiffer());
        $this->assertFalse($period->changeBasis->isTrimmed);
        $this->assertSame('2026-03-31', $period->toArray()['change_basis']['active'][0]['date_to']);
    }

    /**
     * @return array<string, array{0: PeriodComparison, 1: array{0: string, 1: string}, 2: string}>
     */
    public static function manualComparisons(): array
    {
        return [
            'periode sebelumnya' => [PeriodComparison::PreviousPeriod, ['2026-10-29', '2026-11-04'], '2026-11-05T00:00:00+07:00'],
            'bulan lalu' => [PeriodComparison::SamePeriodLastMonth, ['2026-10-05', '2026-10-11'], '2026-10-12T00:00:00+07:00'],
            'tahun lalu' => [PeriodComparison::SamePeriodLastYear, ['2025-11-05', '2025-11-11'], '2025-11-12T00:00:00+07:00'],
        ];
    }

    #[DataProvider('manualComparisons')]
    public function test_manual_comparison_only_changes_the_comparison_period(PeriodComparison $mode, array $dates, string $limit): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-11-05', '2026-11-11', '2026-11-12T00:00:00+07:00')
            ->record($dates[0], $dates[1], $limit);

        $period = $this->resolve($coverage, $this->dateRange('2026-11-05', '2026-11-11', $mode), '2026-11-12T09:00:00+07:00')->toArray();

        $this->assertSame(['2026-11-05', '2026-11-11'], [$period['active']['date_from'], $period['active']['date_to']]);
        $this->assertSame($dates, [$period['comparison']['date_from'], $period['comparison']['date_to']]);
        $this->assertSame($mode->value, $period['comparison_mode']);
    }

    public function test_custom_comparison_reports_when_the_durations_differ(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-10-05', '2026-10-11', '2026-10-12T00:00:00+07:00')
            ->record('2026-09-01', '2026-09-10', '2026-09-11T00:00:00+07:00');

        $period = $this->resolve($coverage, $this->dateRange('2026-10-05', '2026-10-11', PeriodComparison::Custom, '2026-09-01', '2026-09-10'))->toArray();

        $this->assertSame(['2026-10-05', '2026-10-11'], [$period['active']['date_from'], $period['active']['date_to']]);
        $this->assertSame(7, $period['active']['days']);
        $this->assertSame(10, $period['comparison']['days']);
        $this->assertTrue($period['durations_differ']);
    }

    public function test_a_period_without_any_equivalent_date_has_no_comparison(): void
    {
        $coverage = (new InMemoryPeriodCoverage)->record('2026-03-30', '2026-03-31', '2026-04-01T00:00:00+07:00');

        $period = $this->resolve($coverage, $this->dateRange('2026-03-30', '2026-03-31', PeriodComparison::SamePeriodLastMonth), '2026-04-15T10:00:00+07:00')->toArray();

        $this->assertSame('complete', $period['active']['availability']);
        $this->assertNull($period['comparison']);
        $this->assertNull($period['change_basis']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function reversedRanges(): array
    {
        return [
            'rentang aktif' => ['date_to', 'Tanggal akhir tidak boleh sebelum tanggal mulai.', 'active'],
            'rentang pembanding' => ['comparison_to', 'Tanggal akhir pembanding tidak boleh sebelum tanggal mulai.', 'comparison'],
        ];
    }

    #[DataProvider('reversedRanges')]
    public function test_ac02_a_reversed_range_is_rejected_on_its_end_field(string $field, string $message, string $side): void
    {
        $selection = $side === 'active'
            ? $this->dateRange('2026-11-11', '2026-11-05')
            : $this->dateRange('2026-11-05', '2026-11-11', PeriodComparison::Custom, '2026-10-11', '2026-10-05');

        try {
            $this->resolve(new InMemoryPeriodCoverage, $selection);
            $this->fail('The reversed range was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([$field => [$message]], $exception->errors());
        }
    }

    public function test_ac03_today_reads_the_record_at_the_cutoff_and_yesterday_reads_the_latest_state(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-11-10', '2026-11-10', '2026-11-10T14:00:00+07:00')
            ->record('2026-11-10', '2026-11-10', '2026-11-11T00:00:00+07:00')
            ->record('2026-11-09', '2026-11-09', '2026-11-10T00:00:00+07:00');

        $today = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today))->toArray();
        $yesterday = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Yesterday))->toArray();

        $this->assertSame('at_cutoff', $today['comparison']['state_basis']);
        $this->assertSame('partial', $today['comparison']['availability']);
        $this->assertSame('2026-11-10T14:00:00+07:00', $today['comparison']['cutoff_at']);

        $this->assertSame('latest', $yesterday['comparison']['state_basis']);
        $this->assertSame('complete', $yesterday['comparison']['availability']);
        $this->assertSame('2026-11-10T00:00:00+07:00', $yesterday['comparison']['cutoff_at']);
    }

    public function test_ac03_a_missing_comparison_record_is_unavailable_and_keeps_the_intended_hour(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-11-10', '2026-11-10', '2026-11-10T13:00:00+07:00');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today))->toArray();

        $this->assertSame('unavailable', $period['comparison']['availability']);
        $this->assertSame('at_cutoff', $period['comparison']['state_basis']);
        $this->assertSame('2026-11-10T14:00:00+07:00', $period['comparison']['cutoff_at']);
        $this->assertNull($period['change_basis']);
    }

    public function test_ac03_an_incomplete_record_does_not_count_as_the_cutoff(): void
    {
        $coverage = (new InMemoryPeriodCoverage)->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00', isComplete: false);

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today))->toArray();

        $this->assertSame('unavailable', $period['active']['availability']);
        $this->assertNull($period['active']['cutoff_at']);
        $this->assertSame('unavailable', $period['comparison']['availability']);
    }

    public function test_ac04_the_combined_cutoff_follows_the_selected_platforms(): void
    {
        $coverage = (new InMemoryPeriodCoverage(['app-a', 'app-b']))
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00', 'app-a')
            ->record('2026-11-11', '2026-11-11', '2026-11-11T13:00:00+07:00', 'app-b');

        $cutoff = fn (array $platforms) => $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today, platforms: $platforms))->active->cutoffAt->format('H:i');

        $this->assertSame('13:00', $cutoff(['app-a', 'app-b']));
        $this->assertSame('13:00', $cutoff([]));
        $this->assertSame('14:00', $cutoff(['app-a']));

        $coverage->record('2026-11-11', '2026-11-11', '2026-11-11T15:00:00+07:00', 'app-b');

        $this->assertSame('14:00', $cutoff(['app-a', 'app-b']));
    }

    public function test_a_platform_without_metadata_makes_the_combined_period_unavailable(): void
    {
        $coverage = (new InMemoryPeriodCoverage)->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00', 'app-a');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today, platforms: ['app-a', 'app-b']));

        $this->assertSame('unavailable', $period->active->availability->value);
        $this->assertNull($period->active->cutoffAt);
    }

    public function test_the_cutoff_is_read_for_the_selected_dataset_only(): void
    {
        $coverage = (new InMemoryPeriodCoverage)
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-11-11', '2026-11-11', '2026-11-11T09:00:00+07:00', dataset: 'downloaders');

        $period = $this->resolve($coverage, new PeriodSelection(PeriodPreset::Today, dataset: 'downloaders'));

        $this->assertSame('09:00', $period->active->cutoffAt->format('H:i'));
    }
}
