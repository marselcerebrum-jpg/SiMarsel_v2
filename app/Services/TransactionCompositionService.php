<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodStateBasis;
use App\Enums\TransactionDateBasis;
use App\Models\Transaction;
use App\Repositories\DashboardTransactionRepository;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use App\Support\TransactionAggregation\TransactionRecord;

/**
 * The two composition charts of Analisis Performa (FIX-09-BE-01). Both datasets read the shared transaction
 * aggregation rules (FIX-02-BE-02), and the screen and the export use the same output.
 *
 * Status Transaksi (created group, markaz_created_at): every checkout of a buyer row, coupon claims included, in
 * the bar of its report status (effective_status, otherwise source_status). Each bar keeps purchases and claims
 * apart, and checkouts without a status are reported under the chart. Shares divide by every checkout of the group.
 *
 * Perdana vs Perpanjangan: Transaksi Berhasil and Omzet (net_nominal) use the paid group (last valid paid date of a
 * Success); Konversi divides Success purchases by every purchase attempt of the created group, unknown statuses
 * included. Coupon claims and group members are outside all three. Transactions without a promo are counted.
 *
 * Both sides cover the global period: the active window and the comparison window of the resolved period.
 * A value that is not known yet is null, never 0.
 */
class TransactionCompositionService
{
    public const METRIC_SUCCESS_PAYMENTS = 'success_payments';

    public const METRIC_REVENUE = 'revenue';

    public const METRICS = [
        self::METRIC_SUCCESS_PAYMENTS => 'Transaksi Berhasil',
        self::METRIC_REVENUE => 'Omzet',
    ];

    public const NOMINAL_LABEL = 'Total Nominal Transaksi';

    public const PROVISIONAL_LABEL = 'Sementara';

    /** The order of the Status Transaksi bars. */
    private const STATUS_ORDER = [
        MarkazTransactionStatus::Success,
        MarkazTransactionStatus::Pending,
        MarkazTransactionStatus::Expired,
        MarkazTransactionStatus::Canceled,
        MarkazTransactionStatus::Failed,
    ];

    private const UNKNOWN_STATUS = 'unknown';

    private const DATE_BASIS_LABELS = [
        'paid' => 'Berdasarkan tanggal pembayaran',
        'created' => 'Berdasarkan tanggal transaksi dibuat',
    ];

    public function __construct(
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statusDistribution(ResolvedPeriod $period): array
    {
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $active = $this->statusSide($platformIds, $period->active);
        $comparison = $period->comparison === null ? null : $this->statusSide($platformIds, $period->comparison);

        $item = fn (string $key, string $label) => [
            'key' => $key,
            'label' => $label,
            'active' => $active['values'][$key] ?? $this->emptyStatusValues(),
            'comparison' => $comparison === null ? null : ($comparison['values'][$key] ?? $this->emptyStatusValues()),
            'share_change_percentage_point' => $this->pointChange(
                $active['values'][$key]['share_percentage'] ?? null,
                $comparison['values'][$key]['share_percentage'] ?? null,
            ),
        ];

        $isEmpty = $active['totals']['total_checkouts'] === 0;

        return [
            'period' => $period->toArray(),
            'date_basis' => TransactionDateBasis::Created->value,
            'date_basis_label' => self::DATE_BASIS_LABELS['created'],
            'nominal_label' => self::NOMINAL_LABEL,
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Tidak ada data transaksi untuk filter yang dipilih.' : null,
            'active' => $this->sideMeta($active),
            'comparison' => $comparison === null ? null : $this->sideMeta($comparison),
            'statuses' => array_map(fn (MarkazTransactionStatus $status) => $item($this->statusKey($status), $status->label()), self::STATUS_ORDER),
            'unsuccessful' => $item('unsuccessful', 'Tidak Berhasil'),
            'unknown_status' => $item(self::UNKNOWN_STATUS, MarkazTransactionStatus::UNAVAILABLE_LABEL),
        ];
    }

    /**
     * $metric chooses the value and share of each bar; Konversi does not depend on it.
     *
     * @return array<string, mixed>
     */
    public function productTypeDistribution(ResolvedPeriod $period, string $metric = self::METRIC_SUCCESS_PAYMENTS): array
    {
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $active = $this->typeSide($platformIds, $period->active, $metric);
        $comparison = $period->comparison === null ? null : $this->typeSide($platformIds, $period->comparison, $metric);

        $groups = [
            $this->productTypeGroup(Transaction::TYPE_FIRST),
            $this->productTypeGroup(Transaction::TYPE_RENEWAL),
        ];
        $others = array_merge($active['groups'], $comparison['groups'] ?? []);
        ksort($others);

        foreach ($others as $id => $group) {
            if (! in_array($id, array_column($groups, 'id'), true) && $group['key'] === PromoProductTypeService::GROUP_OTHER) {
                $groups[] = $group;
            }
        }

        if (isset($others[PromoProductTypeService::GROUP_UNKNOWN])) {
            $groups[] = $others[PromoProductTypeService::GROUP_UNKNOWN];
        }

        $isEmpty = $active['totals']['success_payments'] === 0 && $active['totals']['purchase_attempts'] === 0;

        return [
            'period' => $period->toArray(),
            'metric' => $metric,
            'default_metric' => self::METRIC_SUCCESS_PAYMENTS,
            'metrics' => array_map(fn (string $key, string $label) => [
                'key' => $key,
                'label' => $label,
                'date_basis' => TransactionDateBasis::Paid->value,
            ], array_keys(self::METRICS), self::METRICS),
            'date_basis' => [
                'metric' => TransactionDateBasis::Paid->value,
                'metric_label' => self::DATE_BASIS_LABELS['paid'],
                'conversion' => TransactionDateBasis::Created->value,
                'conversion_label' => self::DATE_BASIS_LABELS['created'],
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Tidak ada data transaksi untuk filter yang dipilih.' : null,
            'active' => $this->sideMeta($active),
            'comparison' => $comparison === null ? null : $this->sideMeta($comparison),
            'product_types' => array_map(function (array $group) use ($active, $comparison) {
                $activeValues = $active['values'][$group['id']] ?? $this->typeValues($active, null);
                $comparisonValues = $comparison === null ? null : ($comparison['values'][$group['id']] ?? $this->typeValues($comparison, null));

                return [
                    'key' => $group['key'],
                    'name' => $group['name'],
                    'active' => $activeValues,
                    'comparison' => $comparisonValues,
                    'share_change_percentage_point' => $this->pointChange($activeValues['share_percentage'], $comparisonValues['share_percentage'] ?? null),
                ];
            }, $groups),
        ];
    }

    /**
     * @param  list<int>  $platformIds
     * @return array<string, mixed>
     */
    private function statusSide(array $platformIds, PeriodWindow $window): array
    {
        $side = $this->side($platformIds, $window);

        if ($side['records'] === null) {
            return [...$side, 'totals' => ['total_checkouts' => null, 'purchase_attempts' => null, 'claim_checkouts' => null], 'values' => null];
        }

        $records = $this->aggregations->scopedRecords($side['records'], $this->filter($platformIds, $window, TransactionDateBasis::Created));
        $counts = [];

        foreach ([...array_map($this->statusKey(...), self::STATUS_ORDER), self::UNKNOWN_STATUS] as $key) {
            $counts[$key] = ['purchase_count' => 0, 'claim_count' => 0, 'nominal' => 0.0];
        }

        foreach ($records as $record) {
            $key = $record->reportStatus === null ? self::UNKNOWN_STATUS : $this->statusKey($record->reportStatus);
            $counts[$key][$record->isClaimCoupon ? 'claim_count' : 'purchase_count']++;
            $counts[$key]['nominal'] += $record->nominal;
        }

        $counts['unsuccessful'] = array_reduce(
            ['expired', 'canceled', 'failed'],
            fn (array $carry, string $key) => [
                'purchase_count' => $carry['purchase_count'] + $counts[$key]['purchase_count'],
                'claim_count' => $carry['claim_count'] + $counts[$key]['claim_count'],
                'nominal' => $carry['nominal'] + $counts[$key]['nominal'],
            ],
            ['purchase_count' => 0, 'claim_count' => 0, 'nominal' => 0.0],
        );

        $total = count($records);
        $claims = count(array_filter($records, fn (TransactionRecord $record) => $record->isClaimCoupon));

        return [
            ...$side,
            'totals' => ['total_checkouts' => $total, 'purchase_attempts' => $total - $claims, 'claim_checkouts' => $claims],
            'values' => array_map(fn (array $count) => [
                'count' => $count['purchase_count'] + $count['claim_count'],
                'purchase_count' => $count['purchase_count'],
                'claim_count' => $count['claim_count'],
                'share_percentage' => Ratio::percentage($count['purchase_count'] + $count['claim_count'], $total),
                'nominal' => round($count['nominal'], 2),
            ], $counts),
        ];
    }

    /**
     * @param  list<int>  $platformIds
     * @return array<string, mixed>
     */
    private function typeSide(array $platformIds, PeriodWindow $window, string $metric): array
    {
        $side = $this->side($platformIds, $window);
        $nullTotals = ['success_payments' => null, 'revenue' => null, 'value' => null, 'success_purchases' => null, 'purchase_attempts' => null, 'unknown_status_count' => null];

        if ($side['records'] === null) {
            return [...$side, 'metric' => $metric, 'totals' => $nullTotals, 'groups' => [], 'values' => null];
        }

        $created = $this->filter($platformIds, $window, TransactionDateBasis::Created);
        $paid = $this->filter($platformIds, $window, TransactionDateBasis::Paid);
        $groups = $partitions = [];

        foreach ($side['records'] as $record) {
            $group = $this->productTypeGroup($record->productType);
            $groups[$group['id']] ??= $group;
            $partitions[$group['id']][] = $record;
        }

        // Only a group with a purchase or payment in this window is shown; claims and members never add one.
        $inScope = array_map(
            fn (TransactionRecord $record) => $this->productTypeGroup($record->productType)['id'],
            array_filter(
                [...$this->aggregations->scopedRecords($side['records'], $created), ...$this->aggregations->scopedRecords($side['records'], $paid)],
                fn (TransactionRecord $record) => ! $record->isClaimCoupon,
            ),
        );
        $groups = array_intersect_key($groups, array_flip($inScope));

        $values = [];

        foreach ($partitions as $id => $records) {
            $createdMetrics = $this->aggregations->aggregate($records, $created)['metrics'];
            $paidMetrics = $this->aggregations->aggregate($records, $paid)['metrics'];

            $values[$id] = [
                'success_payments' => $paidMetrics['success_payments'],
                'revenue' => round($paidMetrics['revenue'], 2),
                'success_purchases' => $createdMetrics['success_purchases'],
                'purchase_attempts' => $createdMetrics['purchase_attempts'],
                'unknown_status_count' => $createdMetrics['unknown_status_count'],
            ];
        }

        $totals = ['success_payments' => 0, 'revenue' => 0.0, 'success_purchases' => 0, 'purchase_attempts' => 0, 'unknown_status_count' => 0];

        foreach (array_intersect_key($values, $groups) as $value) {
            foreach ($totals as $key => $total) {
                $totals[$key] = $total + $value[$key];
            }
        }

        $totals['revenue'] = round($totals['revenue'], 2);
        $totals = ['value' => $totals[$metric], ...$totals];
        $side = [...$side, 'metric' => $metric, 'totals' => $totals, 'groups' => $groups];

        return [
            ...$side,
            'values' => array_map(fn (array $value) => $this->typeValues($side, $value), array_intersect_key($values, $groups)),
        ];
    }

    /**
     * One product type on one side. A type without transactions in a known window gives 0, an unknown window null.
     *
     * @param  array<string, mixed>  $side
     * @param  array<string, int|float>|null  $value
     * @return array<string, mixed>
     */
    private function typeValues(array $side, ?array $value): array
    {
        $known = $side['records'] !== null;
        $value ??= $known
            ? ['success_payments' => 0, 'revenue' => 0.0, 'success_purchases' => 0, 'purchase_attempts' => 0, 'unknown_status_count' => 0]
            : ['success_payments' => null, 'revenue' => null, 'success_purchases' => null, 'purchase_attempts' => null, 'unknown_status_count' => null];
        $selected = $value[$side['metric']];
        $isProvisional = $known ? $value['unknown_status_count'] > 0 : null;

        return [
            'value' => $selected,
            'share_percentage' => Ratio::percentage($selected, $side['totals']['value']),
            'success_payments' => $value['success_payments'],
            'revenue' => $value['revenue'],
            'conversion' => [
                'value' => Ratio::percentage($value['success_purchases'], $value['purchase_attempts']),
                'success_purchases' => $value['success_purchases'],
                'purchase_attempts' => $value['purchase_attempts'],
                'unknown_status_count' => $value['unknown_status_count'],
                'is_provisional' => $isProvisional,
                'provisional_label' => $isProvisional ? self::PROVISIONAL_LABEL : null,
            ],
        ];
    }

    /**
     * The stored transactions of one window with its availability. An equivalent-hour comparison must read the
     * state recorded at its cutoff, and the recorded transactions payload has neither the claims per status nor the
     * product types, so that side stays unavailable instead of being rebuilt from the latest state.
     *
     * @param  list<int>  $platformIds
     * @return array{window: PeriodWindow, availability: string, as_of: string|null, source: string|null, records: list<TransactionRecord>|null}
     */
    private function side(array $platformIds, PeriodWindow $window): array
    {
        $unavailable = fn (?string $source) => [
            'window' => $window,
            'availability' => DataAvailability::Unavailable->value,
            'as_of' => null,
            'source' => $source,
            'records' => null,
        ];

        if ($window->stateBasis === PeriodStateBasis::AtCutoff) {
            return $unavailable('snapshot');
        }

        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable) {
            return $unavailable(null);
        }

        $filter = $this->filter($platformIds, $window, TransactionDateBasis::Created);

        if ($filter->availability() === DataAvailability::Unavailable) {
            return $unavailable(null);
        }

        return [
            'window' => $window,
            'availability' => $filter->availability()->value,
            'as_of' => $filter->cutoff()?->toIso8601String(),
            'source' => 'latest',
            'records' => array_map(
                TransactionRecord::fromArray(...),
                $this->dashboardTransactions->contractRows($platformIds, $window->dateFrom, $window->dateTo->addDay()),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $side
     * @return array<string, mixed>
     */
    private function sideMeta(array $side): array
    {
        return [
            'date_from' => $side['window']->dateFrom->toDateString(),
            'date_to' => $side['window']->dateTo->toDateString(),
            'availability' => $side['availability'],
            'as_of' => $side['as_of'],
            'source' => $side['source'],
            'totals' => $side['totals'],
        ];
    }

    /**
     * @param  list<int>  $platformIds
     */
    private function filter(array $platformIds, PeriodWindow $window, TransactionDateBasis $basis): AggregationFilter
    {
        return AggregationFilter::forWindow($platformIds, $window->dateFrom, $window->dateTo->addDay(), $basis, $window->cutoffAt);
    }

    /**
     * Perdana and Perpanjangan match like the database comparison (trimmed, any case); another stored type is its own
     * group, and an empty type is Tidak diketahui.
     *
     * @return array{id: string, key: string, name: string}
     */
    private function productTypeGroup(?string $type): array
    {
        $type = $type === null ? null : trim($type);

        return match (true) {
            $type === null || $type === '' => ['id' => PromoProductTypeService::GROUP_UNKNOWN, 'key' => PromoProductTypeService::GROUP_UNKNOWN, 'name' => PromoProductTypeService::UNKNOWN],
            strcasecmp($type, Transaction::TYPE_FIRST) === 0 => ['id' => PromoProductTypeService::GROUP_FIRST_PURCHASE, 'key' => PromoProductTypeService::GROUP_FIRST_PURCHASE, 'name' => Transaction::TYPE_FIRST],
            strcasecmp($type, Transaction::TYPE_RENEWAL) === 0 => ['id' => PromoProductTypeService::GROUP_RENEWAL, 'key' => PromoProductTypeService::GROUP_RENEWAL, 'name' => Transaction::TYPE_RENEWAL],
            default => ['id' => PromoProductTypeService::GROUP_OTHER.':'.mb_strtolower($type), 'key' => PromoProductTypeService::GROUP_OTHER, 'name' => $type],
        };
    }

    /**
     * @return array<string, null>
     */
    private function emptyStatusValues(): array
    {
        return ['count' => null, 'purchase_count' => null, 'claim_count' => null, 'share_percentage' => null, 'nominal' => null];
    }

    private function pointChange(?float $active, ?float $comparison): ?float
    {
        return $active === null || $comparison === null ? null : round($active - $comparison, 2);
    }

    private function statusKey(MarkazTransactionStatus $status): string
    {
        return strtolower($status->name);
    }
}
