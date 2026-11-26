<?php

namespace App\Repositories;

use App\Enums\TransactionDateBasis;
use App\Models\Transaction;
use App\Support\PromoCodePayload;
use App\Support\TransactionList\TransactionListFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The rows of Daftar Transaksi (FIX-13-BE-01). query() is the one base query of the list and of its export
 * (FIX-14-BE-02): the list paginates it, the export reads it without a page limit, so both select the same rows
 * in the same stable order.
 */
class TransactionListRepository
{
    /**
     * Stored promo_code values without any code: the list shapes json_encode writes for no code, a blank code,
     * or the two code positions left empty.
     */
    private const NO_PROMO_PAYLOADS = ['[]', 'null', '""', '[null]', '[""]', '[null,null]', '["",""]', '[null,""]', '["",null]'];

    /**
     * One row per checkout: buyer rows only (a group member points at its buyer with markaz_ref_id), and only rows
     * the transaction dates contract can read (a Markaz identity and creation time). Claims stay in the list.
     * Sorted newest first on the active date basis, with the local id as the stable tie-break.
     *
     * @return Builder<Transaction>
     */
    public function query(TransactionListFilter $filter): Builder
    {
        $query = $this->mainQuery($filter)
            ->with('platform:id,slug,name')
            ->select('transactions.*')
            ->selectSub(fn (QueryBuilder $members) => $members
                ->from('transactions as members')
                ->selectRaw('COUNT(*)')
                ->whereColumn('members.platform_id', 'transactions.platform_id')
                ->whereColumn('members.markaz_ref_id', 'transactions.markaz_transaction_id'), 'group_member_count')
            ->when($filter->search !== null, fn (Builder $query) => $this->search($query, $filter->search))
            ->when($filter->filtersStatus(), fn (Builder $query) => $query->where(fn (Builder $status) => $status
                ->when($filter->statuses !== [], fn (Builder $known) => $known->whereRaw(
                    Transaction::REPORT_STATUS_EXPRESSION.' IN ('.implode(', ', array_fill(0, count($filter->statuses), '?')).')',
                    array_map(fn ($status) => $status->value, $filter->statuses),
                ))
                ->when($filter->withUnavailableStatus, fn (Builder $unknown) => $unknown->orWhereRaw(Transaction::REPORT_STATUS_EXPRESSION.' IS NULL'))))
            ->when($filter->products !== [], fn (Builder $query) => $query->whereIn('transactions.product', $filter->products))
            ->when($filter->paymentMethods !== [], fn (Builder $query) => $query->whereIn(
                DB::raw("LOWER(TRIM(COALESCE(transactions.payment_method, '')))"),
                array_map(fn (string $method) => mb_strtolower(trim($method)), $filter->paymentMethods),
            ))
            ->when($filter->productTypes !== [], fn (Builder $query) => $query->whereIn('transactions.product_type', $filter->productTypes))
            ->when($filter->promoCodes !== [], fn (Builder $query) => $query->where(function (Builder $codes) use ($filter) {
                foreach ($filter->promoCodes as $code) {
                    $codes->orWhereRaw("UPPER(transactions.promo_code) LIKE ? ESCAPE '!'", ['%"'.self::escapeLike(mb_strtoupper(trim($code))).'"%']);
                }
            }))
            ->when($filter->promo === TransactionListFilter::PROMO_WITH, fn (Builder $query) => $this->withPromo($query))
            ->when($filter->promo === TransactionListFilter::PROMO_WITHOUT, fn (Builder $query) => $this->withoutPromo($query));

        return $filter->dateBasis === TransactionDateBasis::Paid
            ? $query->orderByDesc('transactions.paid_date')->orderByDesc('transactions.paid_time')->orderByDesc('transactions.id')
            : $query->orderByDesc('transactions.markaz_created_at')->orderByDesc('transactions.id');
    }

    /**
     * The values the Filter Lainnya options can take within the main period and platforms of the date basis.
     *
     * @return array{products: list<string>, payment_methods: list<string>, product_types: list<string>, promo_codes: list<string>}
     */
    public function options(TransactionListFilter $filter): array
    {
        $base = fn () => $this->mainQuery($filter)->toBase();
        $distinct = fn (string $column) => $base()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => (string) $value)
            ->all();

        $promoCodes = $base()
            ->whereNotNull('transactions.promo_code')
            ->distinct()
            ->pluck('transactions.promo_code')
            ->flatMap(fn (string $payload) => PromoCodePayload::codesByPosition(json_decode($payload, true)) ?? [])
            ->map(fn (string $code) => mb_strtoupper(trim($code)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'products' => $distinct('transactions.product'),
            'payment_methods' => $base()
                ->whereRaw("TRIM(COALESCE(transactions.payment_method, '')) != ''")
                ->groupByRaw('LOWER(TRIM(transactions.payment_method))')
                ->selectRaw('MIN(TRIM(transactions.payment_method)) as name')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'product_types' => $distinct('transactions.product_type'),
            'promo_codes' => $promoCodes,
        ];
    }

    /**
     * Group member rows of a buyer: the same platform and a markaz_ref_id equal to the buyer's markaz_transaction_id.
     *
     * @return Builder<Transaction>
     */
    public function groupMembers(Transaction $buyer): Builder
    {
        return Transaction::query()
            ->where('platform_id', $buyer->platform_id)
            ->where('markaz_ref_id', $buyer->markaz_transaction_id)
            ->orderBy('markaz_transaction_id');
    }

    /**
     * The buyer row of a group member, on the same platform.
     */
    public function groupBuyer(Transaction $member): ?Transaction
    {
        return Transaction::query()
            ->where('platform_id', $member->platform_id)
            ->where('markaz_transaction_id', $member->markaz_ref_id)
            ->first();
    }

    /**
     * The main Dashboard filter (platforms and period on the active date basis) on the buyer rows.
     *
     * @return Builder<Transaction>
     */
    private function mainQuery(TransactionListFilter $filter): Builder
    {
        $paid = $filter->dateBasis === TransactionDateBasis::Paid;

        return Transaction::query()
            ->whereIn('transactions.platform_id', $filter->platformIds)
            ->whereNull('transactions.markaz_ref_id')
            ->whereNotNull('transactions.markaz_transaction_id')
            ->whereNotNull('transactions.markaz_created_at')
            ->where(function (Builder $period) use ($filter, $paid) {
                foreach ($filter->ranges as [$from, $to]) {
                    $period->orWhere(fn (Builder $range) => $paid
                        ? $range->where('transactions.paid_date', '>=', $from->toDateString())
                            ->where('transactions.paid_date', '<', $to->addDay()->toDateString())
                        : $range->where('transactions.markaz_created_at', '>=', $from->startOfDay()->format('Y-m-d H:i:s'))
                            ->where('transactions.markaz_created_at', '<', $to->addDay()->startOfDay()->format('Y-m-d H:i:s')));
                }
            });
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $term = '%'.self::escapeLike($search).'%';

        $query->where(fn (Builder $fields) => $fields
            ->whereRaw("transactions.trx_id LIKE ? ESCAPE '!'", [$term])
            ->orWhereRaw("transactions.customer_name LIKE ? ESCAPE '!'", [$term])
            ->orWhereRaw("transactions.customer_email LIKE ? ESCAPE '!'", [$term])
            ->orWhereRaw("transactions.customer_phone LIKE ? ESCAPE '!'", [$term]));
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function withPromo(Builder $query): void
    {
        $query->whereNotNull('transactions.promo_code')->whereNotIn('transactions.promo_code', self::NO_PROMO_PAYLOADS);
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function withoutPromo(Builder $query): void
    {
        $query->where(fn (Builder $none) => $none
            ->whereNull('transactions.promo_code')
            ->orWhereIn('transactions.promo_code', self::NO_PROMO_PAYLOADS));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
