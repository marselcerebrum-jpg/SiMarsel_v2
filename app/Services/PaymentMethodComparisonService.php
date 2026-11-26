<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use App\Enums\TransactionDateBasis;
use App\Repositories\DashboardTransactionRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use Carbon\CarbonImmutable;

/**
 * FIX-10-BE-01: Total Transaksi, Transaksi Berhasil, Pending, Tidak Berhasil, Konversi Transaksi, and Omzet per
 * payment method, for the active and the comparison period, with the change of each metric.
 *
 * Every value comes from the shared transaction aggregation (FIX-02-BE-02): the counts and Konversi follow the
 * created date (markaz_created_at), Omzet follows the last valid payment date of a Success and sums net_nominal.
 * Coupon claims count in Total only, and group members never count. The created and paid aggregations run
 * separately and are only joined per method afterwards.
 *
 * Methods that differ only in letter case or whitespace form one row; an empty method forms its own row.
 * A value that is not known is null, never 0.
 */
class PaymentMethodComparisonService
{
    use ComparesWithPreviousValue;

    public const UNASSIGNED_METHOD = 'Belum Ada Metode Pembayaran';

    public const PROVISIONAL_LABEL = 'Sementara';

    public const CHANGE_PERCENT = 'percent';

    public const CHANGE_PERCENTAGE_POINT = 'percentage_point';

    private const UNASSIGNED_KEY = '';

    /** The table columns in their required order; every column after the method shows a comparison. */
    private const COLUMNS = [
        ['key' => 'payment_method', 'label' => 'Metode Pembayaran', 'unit' => null, 'date_basis' => null, 'change_unit' => null],
        ['key' => 'total_transactions', 'label' => 'Total Transaksi', 'unit' => 'count', 'date_basis' => 'created', 'change_unit' => self::CHANGE_PERCENT],
        ['key' => 'success_purchases', 'label' => 'Transaksi Berhasil', 'unit' => 'count', 'date_basis' => 'created', 'change_unit' => self::CHANGE_PERCENT],
        ['key' => 'pending_purchases', 'label' => 'Pending', 'unit' => 'count', 'date_basis' => 'created', 'change_unit' => self::CHANGE_PERCENT],
        ['key' => 'unsuccessful_purchases', 'label' => 'Tidak Berhasil', 'unit' => 'count', 'date_basis' => 'created', 'change_unit' => self::CHANGE_PERCENT],
        ['key' => 'conversion', 'label' => 'Konversi Transaksi', 'unit' => 'percentage', 'date_basis' => 'created', 'change_unit' => self::CHANGE_PERCENTAGE_POINT],
        ['key' => 'revenue', 'label' => 'Omzet', 'unit' => 'currency', 'date_basis' => 'paid', 'change_unit' => self::CHANGE_PERCENT],
    ];

    public function __construct(
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(ResolvedPeriod $period): array
    {
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $activeRows = $this->rows($platformIds, $period->active);
        $comparisonMessage = $this->comparisonUnavailableMessage($period, $platformIds);
        $comparisonRows = $comparisonMessage === null ? $this->rows($platformIds, $period->comparison) : [];

        $activeGroups = $this->groupByMethod($activeRows);
        $comparisonGroups = $this->groupByMethod($comparisonRows);
        $keys = array_keys($activeGroups + $comparisonGroups);

        $active = $this->sideValues($activeGroups, $keys, $platformIds, $period->active);
        $comparison = $comparisonMessage === null ? $this->sideValues($comparisonGroups, $keys, $platformIds, $period->comparison) : null;
        [$activeChange, $comparisonChange] = $this->changeValues($period, $platformIds, $activeGroups, $comparisonGroups, $keys, $active, $comparison);

        $activeAvailability = $this->availability($platformIds, $period->active);
        $isEmpty = $active !== null && ! $this->hasActivity($active);
        $labels = $this->labels([...$activeRows, ...$comparisonRows]);

        $rows = $active === null || $isEmpty ? [] : array_values(array_filter(array_map(
            fn (string $key) => $this->row(
                $key,
                $labels[$key] ?? self::UNASSIGNED_METHOD,
                $active[$key],
                $comparison[$key] ?? null,
                $activeChange[$key] ?? null,
                $comparisonChange[$key] ?? null,
            ),
            $keys,
        ), fn (array $row) => $this->isActive($row['active']) || ($row['comparison'] !== null && $this->isActive($row['comparison']))));

        usort($rows, fn (array $a, array $b) => [
            $a['is_unassigned'], -$a['active']['revenue'], -$a['active']['total_transactions'], $a['name'],
        ] <=> [
            $b['is_unassigned'], -$b['active']['revenue'], -$b['active']['total_transactions'], $b['name'],
        ]);

        return [
            'period' => $period->toArray(),
            'is_empty' => $isEmpty,
            'message' => match (true) {
                $active === null => 'Data periode aktif belum tersedia.',
                $isEmpty => 'Tidak ada data metode pembayaran untuk filter yang dipilih.',
                default => null,
            },
            'active' => $activeAvailability,
            'comparison' => $period->comparison === null ? null : [
                ...$this->availability($platformIds, $period->comparison),
                'source' => $period->comparison->stateBasis === PeriodStateBasis::AtCutoff ? 'snapshot' : 'latest',
                'is_available' => $comparison !== null,
                'message' => $comparisonMessage,
            ],
            'change_basis' => [
                'is_trimmed' => $period->changeBasis?->isTrimmed ?? false,
            ],
            'columns' => self::COLUMNS,
            'rows' => $rows,
        ];
    }

    /**
     * The key used to group a method: case- and whitespace-insensitive, so "QRIS", " qris " and "Qris" match.
     * An empty or missing method returns the unassigned key.
     */
    public static function methodKey(mixed $method): string
    {
        return mb_strtolower(self::displayName($method));
    }

    private static function displayName(mixed $method): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $method));
    }

    /**
     * Why the comparison has no values, or null when it can be read.
     *
     * @param  list<int>  $platformIds
     */
    private function comparisonUnavailableMessage(ResolvedPeriod $period, array $platformIds): ?string
    {
        $comparison = $period->comparison;

        return match (true) {
            $comparison === null => 'Periode ini tidak memiliki tanggal pembanding.',
            $platformIds === [], $comparison->availability === DataAvailability::Unavailable => 'Data periode pembanding belum tersedia.',
            // Recorded snapshots hold platform totals only, and a missing recorded value is never rebuilt from the latest state.
            $comparison->stateBasis === PeriodStateBasis::AtCutoff => 'Pembanding jam setara per metode pembayaran belum tercatat.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>|null  $comparison
     * @param  array<string, mixed>|null  $activeChange
     * @param  array<string, mixed>|null  $comparisonChange
     * @return array<string, mixed>
     */
    private function row(string $key, string $name, array $active, ?array $comparison, ?array $activeChange, ?array $comparisonChange): array
    {
        $changes = [];

        foreach (array_slice(self::COLUMNS, 1) as $column) {
            $changes[$column['key']] = $this->change($column['change_unit'], $activeChange[$column['key']] ?? null, $comparisonChange[$column['key']] ?? null);
        }

        return [
            'key' => $key === self::UNASSIGNED_KEY ? null : $key,
            'name' => $name,
            'is_unassigned' => $key === self::UNASSIGNED_KEY,
            'active' => $active,
            'comparison' => $comparison,
            'changes' => $changes,
        ];
    }

    /**
     * Konversi changes in percentage points; the other metrics change relative to the comparison value, which
     * has no percentage when the comparison is 0. The trend is the direction of the value itself.
     *
     * @return array{value: float|null, unit: string, trend: string|null}
     */
    private function change(string $unit, int|float|null $active, int|float|null $comparison): array
    {
        $known = $active !== null && $comparison !== null;

        return [
            'value' => match (true) {
                ! $known => null,
                $unit === self::CHANGE_PERCENTAGE_POINT => round($active - $comparison, 2),
                default => Ratio::change($active, $comparison),
            },
            'unit' => $unit,
            'trend' => $known ? $this->changeFromPrevious($active, $comparison)['trend'] : null,
        ];
    }

    /**
     * The metrics of each method over the whole window, or null when the window has no known data.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  list<string>  $keys
     * @param  list<int>  $platformIds
     * @return array<string, array<string, mixed>>|null
     */
    private function sideValues(array $groups, array $keys, array $platformIds, PeriodWindow $window): ?array
    {
        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable) {
            return null;
        }

        $filter = fn (TransactionDateBasis $basis) => AggregationFilter::forWindow($platformIds, $window->dateFrom, $window->dateTo->addDay(), $basis, $window->cutoffAt);
        $values = [];

        foreach ($keys as $key) {
            $rows = $groups[$key] ?? [];
            $values[$key] = $this->shape(
                $this->aggregations->aggregate($rows, $filter(TransactionDateBasis::Created))['metrics'],
                $this->aggregations->aggregate($rows, $filter(TransactionDateBasis::Paid))['metrics'],
            );
        }

        return $values;
    }

    /**
     * The values the change is calculated from: the whole periods, or only the matched days when the change basis
     * is trimmed. Null without a change basis or a readable comparison.
     *
     * @param  list<int>  $platformIds
     * @param  array<string, list<array<string, mixed>>>  $activeGroups
     * @param  array<string, list<array<string, mixed>>>  $comparisonGroups
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>|null  $active
     * @param  array<string, array<string, mixed>>|null  $comparison
     * @return array{0: array<string, array<string, mixed>>|null, 1: array<string, array<string, mixed>>|null}
     */
    private function changeValues(ResolvedPeriod $period, array $platformIds, array $activeGroups, array $comparisonGroups, array $keys, ?array $active, ?array $comparison): array
    {
        $basis = $period->changeBasis;

        if ($basis === null || $active === null || $comparison === null) {
            return [null, null];
        }

        if (! $basis->isTrimmed) {
            return [$active, $comparison];
        }

        return [
            $this->rangeValues($activeGroups, $keys, $platformIds, $basis->activeRanges, $period->active),
            $this->rangeValues($comparisonGroups, $keys, $platformIds, $basis->comparisonRanges, $period->comparison),
        ];
    }

    /**
     * The metrics of each method over several ranges of one window, counted as one scope.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  list<string>  $keys
     * @param  list<int>  $platformIds
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return array<string, array<string, mixed>>|null
     */
    private function rangeValues(array $groups, array $keys, array $platformIds, array $ranges, PeriodWindow $window): ?array
    {
        $filters = fn (TransactionDateBasis $basis) => array_map(
            fn (array $range) => AggregationFilter::forWindow($platformIds, $range[0], $range[1], $basis, $window->cutoffAt),
            $ranges,
        );
        $values = [];

        foreach ($keys as $key) {
            $rows = $groups[$key] ?? [];
            $created = $this->aggregations->aggregateWindows($rows, $filters(TransactionDateBasis::Created));
            $paid = $this->aggregations->aggregateWindows($rows, $filters(TransactionDateBasis::Paid));

            if ($created === null || $paid === null) {
                return null;
            }

            $values[$key] = $this->shape($created, $paid);
        }

        return $values;
    }

    /**
     * One side of one method: the six table values and the details behind them.
     *
     * @param  array<string, mixed>  $created
     * @param  array<string, mixed>  $paid
     * @return array<string, mixed>
     */
    private function shape(array $created, array $paid): array
    {
        $breakdown = $created['status_breakdown'];
        $unknown = $created['unknown_status_count'];

        return [
            'total_transactions' => $created['total_checkouts'],
            'success_purchases' => $created['success_purchases'],
            'pending_purchases' => $breakdown['pending']['count'],
            'unsuccessful_purchases' => $created['unsuccessful_purchases'],
            'conversion' => $created['conversion_percentage'],
            'revenue' => round((float) $paid['revenue'], 2),
            'purchase_attempts' => $created['purchase_attempts'],
            'claim_checkouts' => $created['claim_checkouts'],
            'unknown_status_count' => $unknown,
            'conversion_is_provisional' => $unknown > 0,
            'conversion_label' => $unknown > 0 ? self::PROVISIONAL_LABEL : null,
            'unsuccessful_breakdown' => [
                'expired' => $breakdown['expired']['count'],
                'canceled' => $breakdown['canceled']['count'],
                'failed' => $breakdown['failed']['count'],
            ],
            'success_payments' => $paid['success_payments'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $values
     */
    private function hasActivity(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isActive($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function isActive(array $value): bool
    {
        return $value['total_transactions'] > 0 || $value['success_payments'] > 0;
    }

    /**
     * @param  list<int>  $platformIds
     * @return array{availability: string, as_of: string|null}
     */
    private function availability(array $platformIds, PeriodWindow $window): array
    {
        if ($platformIds === []) {
            return ['availability' => DataAvailability::Unavailable->value, 'as_of' => null];
        }

        $filter = AggregationFilter::forWindow($platformIds, $window->dateFrom, $window->dateTo->addDay(), TransactionDateBasis::Created, $window->cutoffAt);

        return ['availability' => $filter->availability()->value, 'as_of' => $filter->cutoff()?->toIso8601String()];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByMethod(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[self::methodKey($row['payment_method'] ?? null)][] = $row;
        }

        return $groups;
    }

    /**
     * The label of each method: its most used spelling, then the first in sort order, so "QRIS" wins a tie with "qris".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function labels(array $rows): array
    {
        $spellings = [];

        foreach ($rows as $row) {
            $name = self::displayName($row['payment_method'] ?? null);

            if ($name !== '') {
                $spellings[mb_strtolower($name)][$name] = ($spellings[mb_strtolower($name)][$name] ?? 0) + 1;
            }
        }

        return array_map(function (array $counts) {
            uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

            return (string) array_key_first($counts);
        }, $spellings);
    }

    /**
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>>
     */
    private function rows(array $platformIds, ?PeriodWindow $window): array
    {
        if ($window === null || $platformIds === [] || $window->availability === DataAvailability::Unavailable) {
            return [];
        }

        return $this->dashboardTransactions->contractRows($platformIds, $window->dateFrom, $window->dateTo->addDay(), ['payment_method']);
    }
}
