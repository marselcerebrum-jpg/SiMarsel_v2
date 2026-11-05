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
 * Funnel and source labels are the names saved on each usage, so renaming a master never relabels history.
 */
class PromoCodeSummaryRepository
{
    /**
     * Sort options and the column each one orders by; the contribution follows the revenue.
     */
    public const SORTS = [
        'code' => 'codes.code',
        'platform' => 'platforms.name',
        'funnel' => 'codes.funnel_name',
        'source' => 'codes.source_name',
        'usages' => 'codes.usages',
        'revenue' => 'codes.revenue',
        'contribution' => 'codes.revenue',
    ];

    public function __construct(private PromoAnalysisRepository $promoAnalysisRepository) {}

    /**
     * One page of the rekap per kode promo.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(PromoAnalysisFilter $filter, string $sort, string $direction, int $perPage): LengthAwarePaginator
    {
        return $this->codes($filter, $sort, $direction)->paginate($perPage)->withQueryString();
    }

    /**
     * One row per platform + normalized code + funnel and source snapshot group, so a code whose usages carry
     * different classifications is split by them. Unregistered codes stay in. Used for the page and, read in
     * chunks, the export; the trailing ids make the order unique, so chunks never skip or repeat a row.
     */
    public function codes(PromoAnalysisFilter $filter, string $sort, string $direction): Builder
    {
        $codes = DB::query()
            ->fromSub($this->labelledUsages($filter), 'usages')
            ->groupBy('platform_id', 'code', 'funnel_id', 'funnel_name', 'source_id', 'source_name')
            ->selectRaw(
                'platform_id, code, funnel_id, funnel_name, source_id, source_name, '
                .'COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue'
            );

        return DB::query()
            ->fromSub($codes, 'codes')
            ->join('platforms', 'platforms.id', '=', 'codes.platform_id')
            ->leftJoin('promo_codes', fn (JoinClause $join) => $join
                ->on('promo_codes.platform_id', '=', 'codes.platform_id')
                ->on('promo_codes.code', '=', 'codes.code'))
            ->selectRaw('codes.*, platforms.slug as platform_slug, platforms.name as platform_name, promo_codes.id as promo_code_id')
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('codes.code')
            ->orderBy('platforms.name')
            ->orderBy('codes.funnel_name')
            ->orderBy('codes.source_name')
            ->orderBy('codes.platform_id')
            ->orderBy('codes.funnel_id')
            ->orderBy('codes.source_id');
    }

    /**
     * The most used codes, one row per platform + normalized code whatever their metadata snapshots. Ordered by
     * usages, the same usage count as the rekap per kode, then by credited revenue, code, and platform.
     *
     * @return Collection<int, object{platform_id: int, code: string, usages: int, revenue: string|float, platform_slug: string, platform_name: string, promo_code_id: int|null}>
     */
    public function topCodes(PromoAnalysisFilter $filter, int $limit): Collection
    {
        $codes = DB::query()
            ->fromSub($this->promoAnalysisRepository->creditedUsages($filter), 'usages')
            ->groupBy('platform_id', 'code')
            ->selectRaw('platform_id, code, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue');

        return DB::query()
            ->fromSub($codes, 'codes')
            ->join('platforms', 'platforms.id', '=', 'codes.platform_id')
            ->leftJoin('promo_codes', fn (JoinClause $join) => $join
                ->on('promo_codes.platform_id', '=', 'codes.platform_id')
                ->on('promo_codes.code', '=', 'codes.code'))
            ->selectRaw('codes.*, platforms.slug as platform_slug, platforms.name as platform_name, promo_codes.id as promo_code_id')
            ->orderByDesc('codes.usages')
            ->orderByDesc('codes.revenue')
            ->orderBy('codes.code')
            ->orderBy('platforms.name')
            ->limit($limit)
            ->get();
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
     * Every code usage of the filter, newest transaction first, with its platform, registration, and funnel and
     * source labels: the rows of the code detail, for every code at once.
     */
    public function usages(PromoAnalysisFilter $filter): Builder
    {
        return DB::query()
            ->fromSub($this->labelledUsages($filter), 'usages')
            ->join('platforms', 'platforms.id', '=', 'usages.platform_id')
            ->leftJoin('promo_codes', fn (JoinClause $join) => $join
                ->on('promo_codes.platform_id', '=', 'usages.platform_id')
                ->on('promo_codes.code', '=', 'usages.code'))
            ->selectRaw('usages.*, platforms.name as platform_name, promo_codes.id as promo_code_id')
            ->orderByDesc('usages.paid_date')
            ->orderByDesc('usages.paid_time')
            ->orderByDesc('usages.transaction_id')
            ->orderBy('usages.code_position');
    }

    /**
     * The transactions that used one code, newest first; a transaction using the code twice is one row with two usages.
     *
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     * @return LengthAwarePaginator<int, object{transaction_id: int, trx_id: string, paid_date: string, paid_time: string|null, nominal: string|float, usages: int, revenue: string|float}>
     */
    public function usageTransactions(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group, int $perPage): LengthAwarePaginator
    {
        return DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code, $group), 'usages')
            ->groupBy('transaction_id', 'trx_id', 'paid_date', 'paid_time', 'nominal')
            ->selectRaw('transaction_id, trx_id, paid_date, paid_time, nominal, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->orderByDesc('paid_date')
            ->orderByDesc('paid_time')
            ->orderByDesc('transaction_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Each usage of the code in the given transactions, with its funnel and source label.
     *
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     * @param  list<int>  $transactionIds
     * @return Collection<int, object>
     */
    public function usagesOfTransactions(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group, array $transactionIds): Collection
    {
        return $this->codeUsages($filter, $platformId, $code, $group)
            ->whereIn('transaction_id', $transactionIds)
            ->select('transaction_id', 'code_position', 'raw_code', 'funnel_id', 'funnel_name', 'source_id', 'source_name')
            ->orderBy('code_position')
            ->get();
    }

    /**
     * Transactions, usages, and credited revenue of the code per paid date, for the given dates.
     *
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     * @param  list<string>  $dates  As returned by usageTransactions().
     * @return Collection<int, object{paid_date: string, transactions: int, usages: int, revenue: string|float}>
     */
    public function dayTotals(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group, array $dates): Collection
    {
        return DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code, $group), 'usages')
            ->whereIn('paid_date', $dates)
            ->groupBy('paid_date')
            ->selectRaw('paid_date, COUNT(DISTINCT transaction_id) as transactions, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->get();
    }

    /**
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     * @return array{transactions: int, usages: int, revenue: float}
     */
    public function usageTotals(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group): array
    {
        $totals = DB::query()
            ->fromSub($this->codeUsages($filter, $platformId, $code, $group), 'usages')
            ->selectRaw('COUNT(DISTINCT transaction_id) as transactions, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->first();

        return [
            'transactions' => (int) $totals->transactions,
            'usages' => (int) $totals->usages,
            'revenue' => (float) $totals->revenue,
        ];
    }

    /**
     * Credited usages with their funnel and source labels: the name saved on the usage, or the current master
     * name only when an older usage has no saved name. The ids stay the snapshot ids.
     */
    private function labelledUsages(PromoAnalysisFilter $filter): Builder
    {
        return DB::query()
            ->fromSub($this->promoAnalysisRepository->creditedUsages($filter), 'usages')
            ->leftJoin('funnels', 'funnels.id', '=', 'usages.funnel_id')
            ->leftJoin('promo_sources', 'promo_sources.id', '=', 'usages.source_id')
            ->selectRaw(
                "usages.*, COALESCE(NULLIF(usages.funnel_name_snapshot, ''), funnels.name) as funnel_name, "
                ."COALESCE(NULLIF(usages.source_name_snapshot, ''), promo_sources.name) as source_name"
            );
    }

    /**
     * The usages of one code, limited to one snapshot group of the rekap, or every group when $group is null.
     *
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     */
    private function codeUsages(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group): Builder
    {
        return DB::query()
            ->fromSub($this->labelledUsages($filter), 'usages')
            ->where('platform_id', $platformId)
            ->where('code', $code)
            ->when($group !== null, function (Builder $query) use ($group) {
                foreach ($group as $column => $value) {
                    $value === null ? $query->whereNull($column) : $query->where($column, $value);
                }
            });
    }
}
