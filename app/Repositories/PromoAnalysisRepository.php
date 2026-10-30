<?php

namespace App\Repositories;

use App\Models\PromoCode;
use App\Models\Transaction;
use App\Repositories\Concerns\AppliesPerformanceFilter;
use App\Services\PaymentMethodDistributionService;
use App\Support\PromoAnalysisFilter;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promo KPIs are counted per unique transaction (one transactions row), never by adding up
 * transaction_promo_codes rows, so a transaction with two codes counts once.
 */
class PromoAnalysisRepository
{
    use AppliesPerformanceFilter;

    /**
     * Payment methods are matched case- and whitespace-insensitively; an empty method is matched as an empty name.
     */
    private const NORMALIZED_PAYMENT_METHOD = "LOWER(TRIM(COALESCE(payment_method, '')))";

    /**
     * A transaction uses a promo when it has at least one promo code usage row.
     */
    private const HAS_PROMO = 'EXISTS (SELECT 1 FROM transaction_promo_codes WHERE transaction_promo_codes.transaction_id = transactions.id)';

    /**
     * Transactions and revenue with and without a promo code, in one query.
     *
     * @return array{transactions: int, promo_transactions: int, non_promo_transactions: int, promo_revenue: float, non_promo_revenue: float}
     */
    public function transactionTotals(PromoAnalysisFilter $filter): array
    {
        $totals = $this->transactions($filter)
            ->toBase()
            ->selectRaw(
                'COUNT(*) as transactions, '
                .'COALESCE(SUM(CASE WHEN '.self::HAS_PROMO.' THEN 1 ELSE 0 END), 0) as promo_transactions, '
                .'COALESCE(SUM(CASE WHEN '.self::HAS_PROMO.' THEN 0 ELSE 1 END), 0) as non_promo_transactions, '
                .'COALESCE(SUM(CASE WHEN '.self::HAS_PROMO.' THEN nominal ELSE 0 END), 0) as promo_revenue, '
                .'COALESCE(SUM(CASE WHEN '.self::HAS_PROMO.' THEN 0 ELSE nominal END), 0) as non_promo_revenue'
            )
            ->first();

        return [
            'transactions' => (int) $totals->transactions,
            'promo_transactions' => (int) $totals->promo_transactions,
            'non_promo_transactions' => (int) $totals->non_promo_transactions,
            'promo_revenue' => (float) $totals->promo_revenue,
            'non_promo_revenue' => (float) $totals->non_promo_revenue,
        ];
    }

    /**
     * Distinct platform + normalized codes used by the filtered transactions, registered or not.
     * With a search, only the codes matching it are counted.
     */
    public function countUsedCodes(PromoAnalysisFilter $filter): int
    {
        $codes = DB::table('transaction_promo_codes')
            ->whereIn('transaction_id', $this->transactions($filter)->select('transactions.id'))
            ->when($filter->hasSearch(), fn (QueryBuilder $query) => $this->whereCodeMatches($query, $filter->search))
            ->select('platform_id_snapshot', 'code_snapshot')
            ->distinct();

        return DB::query()->fromSub($codes, 'used_codes')->count();
    }

    /**
     * Transactions and full revenue per funnel of each transaction's main code, so every transaction
     * falls in exactly one group. Only position 1 determines the funnel. A code standing alone in
     * position 2 still counts as a promo transaction, but belongs to Tanpa Funnel.
     * deleted_funnel_name is the snapshot name of a funnel that was removed from the master.
     *
     * @return Collection<int, object{has_code: int, funnel_id: int|null, deleted_funnel_name: string|null, transactions: int, revenue: string|float}>
     */
    public function funnelTotals(PromoAnalysisFilter $filter): Collection
    {
        $mainUsages = DB::table('transaction_promo_codes as usages')
            ->where('usages.code_position', 1)
            ->select('usages.transaction_id', 'usages.funnel_id_snapshot', 'usages.funnel_name_snapshot');

        $classified = $this->transactions($filter)
            ->toBase()
            ->leftJoinSub($mainUsages, 'main_usages', 'main_usages.transaction_id', '=', 'transactions.id')
            ->selectRaw(
                'transactions.nominal, '
                .'CASE WHEN '.self::HAS_PROMO.' THEN 1 ELSE 0 END as has_code, '
                .'main_usages.funnel_id_snapshot as funnel_id, '
                .'CASE WHEN main_usages.funnel_id_snapshot IS NULL THEN main_usages.funnel_name_snapshot END as deleted_funnel_name'
            );

        return DB::query()
            ->fromSub($classified, 'classified')
            ->groupBy('has_code', 'funnel_id', 'deleted_funnel_name')
            ->selectRaw('has_code, funnel_id, deleted_funnel_name, COUNT(*) as transactions, COALESCE(SUM(nominal), 0) as revenue')
            ->get();
    }

    /**
     * Usages and credited revenue per source of each code, from the code's own snapshot. Every usage of the
     * filtered transactions counts once, so a two-code transaction adds a usage to both sources. The full
     * nominal goes to the code in the highest position: the only code, or the second (reseller) code of a
     * two-code transaction, whose main code gets Rp0. With a search, only the codes matching it are counted.
     * deleted_source_name is the snapshot name of a source that was removed from the master.
     *
     * @return Collection<int, object{source_id: int|null, deleted_source_name: string|null, usages: int, revenue: string|float}>
     */
    public function sourceTotals(PromoAnalysisFilter $filter): Collection
    {
        $usages = DB::table('transaction_promo_codes')
            ->join('transactions', 'transactions.id', '=', 'transaction_promo_codes.transaction_id')
            ->whereIn('transaction_promo_codes.transaction_id', $this->transactions($filter)->select('transactions.id'))
            ->when($filter->hasSearch(), fn (QueryBuilder $query) => $this->whereCodeMatches($query, $filter->search))
            ->selectRaw(
                'transaction_promo_codes.source_id_snapshot as source_id, '
                .'CASE WHEN transaction_promo_codes.source_id_snapshot IS NULL THEN transaction_promo_codes.source_name_snapshot END as deleted_source_name, '
                .'CASE WHEN transaction_promo_codes.code_position = (SELECT MAX(positions.code_position) FROM transaction_promo_codes positions '
                .'WHERE positions.transaction_id = transaction_promo_codes.transaction_id) THEN transactions.nominal ELSE 0 END as revenue'
            );

        return DB::query()
            ->fromSub($usages, 'credited_usages')
            ->groupBy('source_id', 'deleted_source_name')
            ->selectRaw('source_id, deleted_source_name, COUNT(*) as usages, COALESCE(SUM(revenue), 0) as revenue')
            ->get();
    }

    /**
     * Payment methods of successful transactions, for the filter options.
     *
     * @return Collection<int, string|null> Names as stored, without surrounding spaces; null for an empty method.
     */
    public function paymentMethods(): Collection
    {
        return Transaction::query()
            ->promoAnalyzable()
            ->toBase()
            ->selectRaw('MIN(TRIM(payment_method)) as name')
            ->groupByRaw(self::NORMALIZED_PAYMENT_METHOD)
            ->pluck('name');
    }

    /**
     * Successful transactions matching every part of the filter.
     *
     * @return Builder<Transaction>
     */
    private function transactions(PromoAnalysisFilter $filter): Builder
    {
        return $this->applyPerformanceFilter(Transaction::query()->promoAnalyzable(), $filter->period, 'paid_date', 'platform_id')
            ->when($filter->paymentMethods !== [], fn (Builder $query) => $query->whereIn(
                DB::raw(self::NORMALIZED_PAYMENT_METHOD),
                array_map(fn (string $method) => $method === PaymentMethodDistributionService::UNKNOWN_METHOD ? '' : mb_strtolower($method), $filter->paymentMethods),
            ))
            ->when($filter->hasSearch(), fn (Builder $query) => $query->whereExists(fn (QueryBuilder $query) => $this
                ->whereCodeMatches($query->selectRaw('1')->from('transaction_promo_codes')
                    ->whereColumn('transaction_promo_codes.transaction_id', 'transactions.id'), $filter->search)));
    }

    /**
     * Partial match on the normalized code or the raw Markaz value, so unregistered codes are found too.
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function whereCodeMatches(QueryBuilder $query, string $search): QueryBuilder
    {
        $search = trim($search);

        return $query->where(fn (QueryBuilder $query) => $query
            ->where('transaction_promo_codes.code_snapshot', 'like', '%'.PromoCode::normalizeCode($search).'%')
            ->orWhere('transaction_promo_codes.raw_code', 'like', '%'.$search.'%'));
    }
}
