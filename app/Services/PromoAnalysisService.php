<?php

namespace App\Services;

use App\Models\Funnel;
use App\Models\PromoSource;
use App\Repositories\PromoAnalysisRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PromoAnalysisFilter;
use Illuminate\Support\Collection;

class PromoAnalysisService
{
    use ComparesWithPreviousValue;

    public const GROUP_FUNNEL = 'funnel';

    public const GROUP_DELETED_FUNNEL = 'deleted_funnel';

    public const GROUP_NO_FUNNEL = 'no_funnel';

    public const GROUP_NO_CODE = 'no_code';

    public const NO_FUNNEL = 'Tanpa Funnel';

    public const NO_CODE = 'Tanpa Kode';

    public const GROUP_SOURCE = 'source';

    public const GROUP_DELETED_SOURCE = 'deleted_source';

    public const GROUP_NO_SOURCE = 'no_source';

    public const NO_SOURCE = 'Tanpa Sumber';

    public function __construct(
        private PromoAnalysisRepository $promoAnalysisRepository,
        private PlatformService $platformService,
    ) {}

    /**
     * Options for the Analisis Promo filter: the global platforms and the payment methods found in transactions.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'platforms' => $this->platformService->list(),
            'payment_methods' => $this->promoAnalysisRepository->paymentMethods()
                ->map(fn (?string $name) => filled($name) ? $name : PaymentMethodDistributionService::UNKNOWN_METHOD)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * The KPI cards for the filter, compared with the filter's previous period.
     *
     * @return array<string, mixed>
     */
    public function summarize(PromoAnalysisFilter $filter): array
    {
        $current = $this->metrics($filter);
        $previous = $this->metrics($filter->previous());

        $isEmpty = $current['promo_transactions'] + $current['non_promo_transactions'] === 0;

        return [
            'filter' => $filter->toArray(),
            'previous_period' => $filter->previousToArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'kpis' => collect($current)
                ->map(fn (int|float|null $value, string $key) => [
                    'value' => $value,
                    'previous_value' => $previous[$key],
                    ...$this->changeFromPrevious($value, $previous[$key]),
                ])
                ->all(),
        ];
    }

    /**
     * Transactions, revenue, and revenue share per funnel of each transaction's main code. Every master funnel
     * is listed, even without transactions, followed by funnels removed from the master, Tanpa Funnel, and Tanpa Kode.
     *
     * @return array<string, mixed>
     */
    public function funnelBreakdown(PromoAnalysisFilter $filter): array
    {
        $totals = $this->promoAnalysisRepository->funnelTotals($filter)->map(fn (object $row) => [
            'key' => match (true) {
                ! $row->has_code => self::GROUP_NO_CODE,
                $row->funnel_id !== null => self::GROUP_FUNNEL,
                filled($row->deleted_funnel_name) => self::GROUP_DELETED_FUNNEL,
                default => self::GROUP_NO_FUNNEL,
            },
            'funnel_id' => $row->funnel_id === null ? null : (int) $row->funnel_id,
            'name' => $row->deleted_funnel_name,
            'transactions' => (int) $row->transactions,
            'revenue' => (float) $row->revenue,
        ]);

        $group = fn (string $key, ?Funnel $funnel, string $name, Collection $rows) => [
            'key' => $key,
            'funnel' => $funnel ? ['id' => $funnel->id, 'name' => $funnel->name] : null,
            'name' => $name,
            'transactions' => $rows->sum('transactions'),
            'revenue' => round($rows->sum('revenue'), 2),
        ];

        $groups = collect([
            ...Funnel::query()->orderBy('id')->get()->map(fn (Funnel $funnel) => $group(
                self::GROUP_FUNNEL, $funnel, $funnel->name, $totals->where('funnel_id', $funnel->id),
            )),
            ...$totals->where('key', self::GROUP_DELETED_FUNNEL)->groupBy('name')->sortKeys()
                ->map(fn (Collection $rows, string $name) => $group(self::GROUP_DELETED_FUNNEL, null, $name, $rows))
                ->values(),
            $group(self::GROUP_NO_FUNNEL, null, self::NO_FUNNEL, $totals->where('key', self::GROUP_NO_FUNNEL)),
            $group(self::GROUP_NO_CODE, null, self::NO_CODE, $totals->where('key', self::GROUP_NO_CODE)),
        ]);

        $totalTransactions = $totals->sum('transactions');
        $totalRevenue = round($totals->sum('revenue'), 2);
        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'transactions' => $totalTransactions,
                'revenue' => $totalRevenue,
            ],
            'funnels' => $groups->map(fn (array $row) => [
                ...$row,
                'contribution_percentage' => $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 2) : 0,
            ])->all(),
        ];
    }

    /**
     * Usages, credited revenue, and revenue share per source. Every master source is listed, even without usages,
     * followed by sources removed from the master and Tanpa Sumber. Transactions without a code have no usage,
     * so their revenue stays out of this table and the percentages can add up to less than 100.
     *
     * @return array<string, mixed>
     */
    public function sourceBreakdown(PromoAnalysisFilter $filter): array
    {
        $totals = $this->promoAnalysisRepository->sourceTotals($filter)->map(fn (object $row) => [
            'key' => match (true) {
                $row->source_id !== null => self::GROUP_SOURCE,
                filled($row->deleted_source_name) => self::GROUP_DELETED_SOURCE,
                default => self::GROUP_NO_SOURCE,
            },
            'source_id' => $row->source_id === null ? null : (int) $row->source_id,
            'name' => $row->deleted_source_name,
            'usages' => (int) $row->usages,
            'revenue' => (float) $row->revenue,
        ]);

        $group = fn (string $key, ?PromoSource $source, string $name, Collection $rows) => [
            'key' => $key,
            'source' => $source ? ['id' => $source->id, 'name' => $source->name] : null,
            'name' => $name,
            'usages' => $rows->sum('usages'),
            'revenue' => round($rows->sum('revenue'), 2),
        ];

        $groups = collect([
            ...PromoSource::query()->orderBy('id')->get()->map(fn (PromoSource $source) => $group(
                self::GROUP_SOURCE, $source, $source->name, $totals->where('source_id', $source->id),
            )),
            ...$totals->where('key', self::GROUP_DELETED_SOURCE)->groupBy('name')->sortKeys()
                ->map(fn (Collection $rows, string $name) => $group(self::GROUP_DELETED_SOURCE, null, $name, $rows))
                ->values(),
            $group(self::GROUP_NO_SOURCE, null, self::NO_SOURCE, $totals->where('key', self::GROUP_NO_SOURCE)),
        ]);

        $transactions = $this->promoAnalysisRepository->transactionTotals($filter);
        $totalRevenue = round($transactions['promo_revenue'] + $transactions['non_promo_revenue'], 2);
        $isEmpty = $transactions['transactions'] === 0;

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'transactions' => $transactions['transactions'],
                'revenue' => $totalRevenue,
                'usages' => $totals->sum('usages'),
                'source_revenue' => round($totals->sum('revenue'), 2),
            ],
            'sources' => $groups->map(fn (array $row) => [
                ...$row,
                'contribution_percentage' => $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 2) : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function metrics(PromoAnalysisFilter $filter): array
    {
        $totals = $this->promoAnalysisRepository->transactionTotals($filter);

        return [
            'promo_transactions' => $totals['promo_transactions'],
            'non_promo_transactions' => $totals['non_promo_transactions'],
            'promo_revenue' => round($totals['promo_revenue'], 2),
            'non_promo_revenue' => round($totals['non_promo_revenue'], 2),
            'promo_transaction_percentage' => $totals['transactions'] > 0
                ? round($totals['promo_transactions'] / $totals['transactions'] * 100, 2)
                : null,
            'used_promo_codes' => $this->promoAnalysisRepository->countUsedCodes($filter),
        ];
    }
}
