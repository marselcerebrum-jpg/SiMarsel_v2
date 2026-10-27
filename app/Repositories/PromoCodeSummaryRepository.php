<?php

namespace App\Repositories;

use App\Support\PromoAnalysisFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rekap per kode promo and its usage detail, built on the credited usages of the promo analysis filter:
 * one usage per code occurrence, and the full nominal credited to the code in the highest position.
 */
class PromoCodeSummaryRepository
{
    /**
     * Sort options and the column each one orders by; the contribution follows the revenue.
     */
    public const SORTS = [
        'code' => 'codes.code',
        'platform' => 'platforms.name',
        'funnel' => 'funnel_name',
        'source' => 'source_name',
        'usages' => 'codes.usages',
        'revenue' => 'codes.revenue',
        'contribution' => 'codes.revenue',
    ];

    public function __construct(private PromoAnalysisRepository $promoAnalysisRepository) {}

    /**
     * One row per platform + normalized code + historical funnel and source, so a code whose metadata changed
     * during the period is split by the metadata that applied to each usage. Unregistered codes stay in.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(PromoAnalysisFilter $filter, string $sort, string $direction, int $perPage): LengthAwarePaginator
    {
        $codes = DB::query()
            ->fromSub($this->promoAnalysisRepository->creditedUsages($filter), 'usages')
            ->groupBy('platform_id', 'code', 'funnel_id', 'deleted_funnel_name', 'source_id', 'deleted_source_name')
            ->selectRaw(
                'platform_id, code, funnel_id, deleted_funnel_name, source_id, deleted_source_name, '
                .'COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue'
            );

        return DB::query()
            ->fromSub($codes, 'codes')
            ->join('platforms', 'platforms.id', '=', 'codes.platform_id')
            ->leftJoin('funnels', 'funnels.id', '=', 'codes.funnel_id')
            ->leftJoin('promo_sources', 'promo_sources.id', '=', 'codes.source_id')
            ->leftJoin('promo_codes', fn (JoinClause $join) => $join
                ->on('promo_codes.platform_id', '=', 'codes.platform_id')
                ->on('promo_codes.code', '=', 'codes.code'))
            ->selectRaw(
                'codes.*, platforms.slug as platform_slug, platforms.name as platform_name, '
                .'COALESCE(funnels.name, codes.deleted_funnel_name) as funnel_name, '
                .'COALESCE(promo_sources.name, codes.deleted_source_name) as source_name, '
                .'promo_codes.id as promo_code_id'
            )
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('codes.code')
            ->orderBy('platforms.name')
            ->orderBy('funnel_name')
            ->orderBy('source_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Usages and credited revenue of every code in the filter.
     *
     * @return array{usages: int, revenue: float}
     */
    public function totals(PromoAnalysisFilter $filter): array
    {
        $totals = DB::query()
            ->fromSub($this->promoAnalysisRepository->creditedUsages($filter), 'usages')
            ->selectRaw('COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->first();

        return ['usages' => (int) $totals->usages, 'revenue' => (float) $totals->revenue];
    }

    /**
     * The distinct raw values written by Markaz for the given platform + code pairs, exactly as written.
     *
     * @param  list<array{platform_id: int, code: string}>  $pairs
     * @return Collection<string, list<string>> Keyed by "platform_id|code".
     */
    public function rawCodes(PromoAnalysisFilter $filter, array $pairs): Collection
    {
        if ($pairs === []) {
            return collect();
        }

        return DB::query()
            ->fromSub($this->promoAnalysisRepository->creditedUsages($filter), 'usages')
            ->where(function (Builder $query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn (Builder $query) => $query->where('platform_id', $pair['platform_id'])->where('code', $pair['code']));
                }
            })
            // Grouped by the exact bytes: MySQL's case-insensitive collation would merge "CPNS26" and "cpns26".
            ->groupBy('platform_id', 'code')
            ->groupByRaw('HEX(raw_code)')
            ->selectRaw('platform_id, code, MIN(raw_code) as raw_code')
            ->get()
            ->groupBy(fn (object $row) => $row->platform_id.'|'.$row->code)
            ->map(fn (Collection $rows) => $rows->pluck('raw_code')->sort(fn (string $a, string $b) => strcmp($a, $b))->values()->all());
    }

    /**
     * The transactions that used one code, newest first; a transaction using the code twice is one row with two usages.
     *
     * @return LengthAwarePaginator<int, object{transaction_id: int, trx_id: string, paid_date: string, paid_time: string|null, nominal: string|float, usages: int, revenue: string|float}>
     */
    public function usageTransactions(PromoAnalysisFilter $filter, int $platformId, string $code, int $perPage): LengthAwarePaginator
    {
        return DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code), 'usages')
            ->groupBy('transaction_id', 'trx_id', 'paid_date', 'paid_time', 'nominal')
            ->selectRaw('transaction_id, trx_id, paid_date, paid_time, nominal, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->orderByDesc('paid_date')
            ->orderByDesc('paid_time')
            ->orderByDesc('transaction_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Each usage of the code in the given transactions, with the current name of its historical funnel and source.
     *
     * @param  list<int>  $transactionIds
     * @return Collection<int, object>
     */
    public function usagesOfTransactions(PromoAnalysisFilter $filter, int $platformId, string $code, array $transactionIds): Collection
    {
        return DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code)->whereIn('transaction_promo_codes.transaction_id', $transactionIds), 'usages')
            ->leftJoin('funnels', 'funnels.id', '=', 'usages.funnel_id')
            ->leftJoin('promo_sources', 'promo_sources.id', '=', 'usages.source_id')
            ->selectRaw(
                'usages.transaction_id, usages.code_position, usages.raw_code, usages.funnel_id, usages.source_id, '
                .'COALESCE(funnels.name, usages.deleted_funnel_name) as funnel_name, '
                .'COALESCE(promo_sources.name, usages.deleted_source_name) as source_name'
            )
            ->orderBy('usages.code_position')
            ->get();
    }

    /**
     * Transactions, usages, and credited revenue of the code per paid date, for the given dates.
     *
     * @param  list<string>  $dates  As returned by usageTransactions().
     * @return Collection<int, object{paid_date: string, transactions: int, usages: int, revenue: string|float}>
     */
    public function dayTotals(PromoAnalysisFilter $filter, int $platformId, string $code, array $dates): Collection
    {
        return DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code), 'usages')
            ->whereIn('paid_date', $dates)
            ->groupBy('paid_date')
            ->selectRaw('paid_date, COUNT(DISTINCT transaction_id) as transactions, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->get();
    }

    /**
     * @return array{transactions: int, usages: int, revenue: float}
     */
    public function usageTotals(PromoAnalysisFilter $filter, int $platformId, string $code): array
    {
        $totals = DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code), 'usages')
            ->selectRaw('COUNT(DISTINCT transaction_id) as transactions, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->first();

        return [
            'transactions' => (int) $totals->transactions,
            'usages' => (int) $totals->usages,
            'revenue' => (float) $totals->revenue,
        ];
    }

    private function codeUsages(PromoAnalysisFilter $filter, int $platformId, string $code): Builder
    {
        return $this->promoAnalysisRepository->creditedUsages($filter)
            ->where('transaction_promo_codes.platform_id_snapshot', $platformId)
            ->where('transaction_promo_codes.code_snapshot', $code);
    }
}
