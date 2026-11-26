<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Repositories\DashboardTransactionRepository;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;

/**
 * Jam Ramai Pembelian: Success payments and revenue per WIB hour of the payment (24 slots), for the active period
 * and its comparison, optionally limited to one day of the week on both sides. Every value comes from
 * TransactionAggregationService::paymentsByHour(), so buyers, claims, statuses, and revenue follow the shared
 * transaction rules. Both sides read the latest stored state up to their own complete-data cutoff.
 */
class PeakPurchaseHourService
{
    /**
     * ISO day of the week (1 = Monday) => label.
     */
    public const DAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    public const NO_MATCHING_DAY = 'Tidak ada hari yang sama di periode pembanding.';

    public function __construct(
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
    ) {}

    /**
     * The days of the week the active period contains, in calendar order of the week. A one-day period has no day
     * choice, so it returns none.
     *
     * @return list<int>
     */
    public static function dayOptions(ResolvedPeriod $period): array
    {
        if ($period->active->days() < 2) {
            return [];
        }

        $days = array_unique(array_map(fn (CarbonImmutable $day) => $day->dayOfWeekIso, self::days($period->active, null)));
        sort($days);

        return array_values($days);
    }

    /**
     * @param  int|null  $dayOfWeek  ISO day of the week; ignored for a one-day period.
     * @return array<string, mixed>
     */
    public function hourly(ResolvedPeriod $period, ?int $dayOfWeek = null): array
    {
        $options = self::dayOptions($period);
        $dayOfWeek = in_array($dayOfWeek, $options, true) ? $dayOfWeek : null;
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);

        $active = $this->side($platformIds, $period->active, $dayOfWeek);
        $comparison = $period->comparison === null ? null : $this->side($platformIds, $period->comparison, $dayOfWeek);
        $comparisonMessage = $comparison !== null && $comparison['days'] === 0 ? self::NO_MATCHING_DAY : null;

        if ($comparisonMessage !== null) {
            $comparison = null;
        }

        return [
            'timezone' => TransactionRecord::TIMEZONE,
            'day_selectable' => $options !== [],
            'day_of_week' => $dayOfWeek,
            'day_label' => $dayOfWeek === null ? 'Semua Hari' : self::DAYS[$dayOfWeek],
            'day_options' => array_map(fn (int $day) => ['value' => $day, 'label' => self::DAYS[$day]], $options),
            'active' => $this->summary($active),
            'comparison' => $comparison === null ? null : $this->summary($comparison),
            'comparison_message' => $comparisonMessage,
            'slots' => array_map(fn (int $hour) => [
                'hour' => $hour,
                'label' => sprintf('%02d.00–%02d.59', $hour, $hour),
                'start' => sprintf('%02d:00', $hour),
                'end' => sprintf('%02d:59', $hour),
                'active' => $this->slot($active['slots'][$hour]),
                'comparison' => $comparison === null ? null : $this->slot($comparison['slots'][$hour]),
            ], range(0, 23)),
        ];
    }

    /**
     * @param  list<int>  $platformIds
     * @return array{window: PeriodWindow, days: int, slots: list<array<string, mixed>>}
     */
    private function side(array $platformIds, PeriodWindow $window, ?int $dayOfWeek): array
    {
        $days = self::days($window, $dayOfWeek);
        $known = $platformIds !== [] && $window->availability !== DataAvailability::Unavailable;
        $rows = $known && $days !== []
            ? $this->dashboardTransactions->contractRows($platformIds, $window->dateFrom, $window->dateTo->addDay())
            : [];

        return [
            'window' => $window,
            'days' => count($days),
            'slots' => $this->aggregations->paymentsByHour($rows, $platformIds, $days, $known ? $window->cutoffAt : null),
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private static function days(PeriodWindow $window, ?int $dayOfWeek): array
    {
        $days = [];

        foreach (CarbonPeriodImmutable::create($window->dateFrom, $window->dateTo) as $day) {
            if ($dayOfWeek === null || $day->dayOfWeekIso === $dayOfWeek) {
                $days[] = $day->setTimezone(TransactionRecord::TIMEZONE)->startOfDay();
            }
        }

        return $days;
    }

    /**
     * The side's dates and totals over the known slots. The availability is unavailable when no slot is known,
     * complete when every slot is complete, and partial otherwise.
     *
     * @param  array{window: PeriodWindow, days: int, slots: list<array<string, mixed>>}  $side
     * @return array<string, mixed>
     */
    private function summary(array $side): array
    {
        $known = array_filter($side['slots'], fn (array $slot) => $slot['availability'] !== DataAvailability::Unavailable->value);
        $states = array_unique(array_column($side['slots'], 'availability'));
        $asOf = array_filter(array_column($known, 'as_of'));

        return [
            'date_from' => $side['window']->dateFrom->toDateString(),
            'date_to' => $side['window']->dateTo->toDateString(),
            'days' => $side['days'],
            'availability' => match (true) {
                $known === [] => DataAvailability::Unavailable->value,
                $states === [DataAvailability::Complete->value] => DataAvailability::Complete->value,
                default => DataAvailability::Partial->value,
            },
            'as_of' => $asOf === [] ? null : max($asOf),
            'totals' => [
                'success_payments' => $known === [] ? null : array_sum(array_column($known, 'success_payments')),
                'revenue' => $known === [] ? null : round(array_sum(array_column($known, 'revenue')), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function slot(array $slot): array
    {
        return [
            'success_payments' => $slot['success_payments'],
            'revenue' => $slot['revenue'],
            'availability' => $slot['availability'],
            'as_of' => $slot['as_of'],
        ];
    }
}
