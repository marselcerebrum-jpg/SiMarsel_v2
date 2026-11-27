<?php

namespace App\Services;

use App\Enums\MarkazTransactionStatus;
use App\Models\Transaction;
use App\Repositories\DashboardTransactionRepository;
use App\Repositories\TransactionListRepository;
use App\Support\PerformanceFilter;
use App\Support\PromoCodePayload;
use App\Support\TransactionAggregation\TransactionRecord;
use App\Support\TransactionList\TransactionListFilter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Daftar Transaksi and its detail panel (FIX-13-BE-01). The list reads TransactionListRepository::query(), the same
 * base query the export reads, and returns only the table fields; contacts are returned by the detail only.
 * Status labels and revenue contribution follow the shared transaction rules (FIX-02-BE-02).
 */
class TransactionListService
{
    public function __construct(
        private TransactionListRepository $transactionList,
        private DashboardTransactionRepository $dashboardTransactions,
    ) {}

    /**
     * The main Dashboard period and platforms for the list: one range per selected period range.
     *
     * @param  array<string, mixed>  $local  Local list filters, by TransactionListFilter constructor argument name.
     */
    public function filter(PerformanceFilter $performanceFilter, array $local = []): TransactionListFilter
    {
        return new TransactionListFilter(...[
            'platformIds' => $this->dashboardTransactions->platformIds($performanceFilter->platforms),
            'ranges' => $performanceFilter->ranges,
            ...$local,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(TransactionListFilter $filter, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->transactionList->query($filter)
            ->paginate($perPage, page: $page)
            ->through(fn (Transaction $transaction) => $this->row($transaction));
    }

    /**
     * The values of Filter Lainnya within the main period and platforms.
     *
     * @return array<string, list<string>>
     */
    public function options(TransactionListFilter $filter): array
    {
        return $this->transactionList->options($filter);
    }

    /**
     * One list row: only the fields of the table.
     *
     * @return array<string, mixed>
     */
    public function row(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'trx_id' => $transaction->trx_id,
            ...$this->dates($transaction),
            'platform' => $this->platform($transaction),
            'product' => $transaction->product,
            'customer_name' => $transaction->customer_name,
            'payment_method' => $this->blankToNull($transaction->payment_method),
            'nominal' => $this->money($transaction->nominal),
            'status' => $this->status($transaction),
            'is_claim_coupon' => $transaction->is_claim_coupon,
            'group_member_count' => (int) ($transaction->group_member_count ?? 0),
        ];
    }

    /**
     * The detail panel of one transaction: product type, promo codes, contacts when allowed, the five financial
     * details, and the group (members of a buyer, or the buyer of a member).
     *
     * @return array<string, mixed>
     */
    public function detail(Transaction $transaction, bool $withContact): array
    {
        $transaction->loadMissing('platform:id,slug,name');
        $isMember = $transaction->markaz_ref_id !== null;
        $buyer = $isMember ? $this->transactionList->groupBuyer($transaction) : null;
        $members = $isMember ? collect() : $this->transactionList->groupMembers($transaction)->get();

        return [
            'id' => $transaction->id,
            'trx_id' => $transaction->trx_id,
            'markaz_transaction_id' => $transaction->markaz_transaction_id,
            ...$this->dates($transaction),
            'platform' => $this->platform($transaction),
            'product' => $transaction->product,
            'product_type' => $transaction->product_type,
            'payment_method' => $this->blankToNull($transaction->payment_method),
            'promo_codes' => array_values(PromoCodePayload::codesByPosition($transaction->promo_code) ?? []),
            'status' => $this->status($transaction),
            'is_claim_coupon' => $transaction->is_claim_coupon,
            'customer' => $this->customer($transaction, $withContact),
            'financials' => [
                'product_price' => $this->money($transaction->product_price),
                'voucher_discount' => $this->money($transaction->voucher_discount),
                'admin_fee' => $this->money($transaction->admin_fee),
                'nominal' => $this->money($transaction->nominal),
                'revenue_contribution' => $this->revenueContribution($transaction),
            ],
            'group' => [
                'role' => $isMember ? 'member' : ($members->isEmpty() ? null : 'buyer'),
                'buyer' => $buyer === null ? null : $this->groupRow($buyer, $withContact),
                'members' => $members->map(fn (Transaction $member) => $this->groupRow($member, $withContact))->values()->all(),
            ],
        ];
    }

    /**
     * net_nominal of a buyer row that counts as a payment: a Success with a valid paid date that is not a claim.
     * It is counted in Omzet on its Tanggal Dibayar. Null (shown as — and an empty export cell) otherwise.
     */
    public function revenueContribution(Transaction $transaction): ?float
    {
        if ($transaction->markaz_transaction_id === null || $transaction->markaz_created_at === null) {
            return null;
        }

        $record = TransactionRecord::fromArray([
            ...$transaction->only(['platform_id', 'markaz_transaction_id', 'markaz_ref_id', 'markaz_customer_id', 'markaz_created_at', 'paid_date', 'paid_time', 'source_status', 'effective_status', 'is_claim_coupon']),
            'nominal' => (float) $transaction->nominal,
            'net_nominal' => (float) $transaction->net_nominal,
        ]);

        if ($record->isGroupMember() || $record->isClaimCoupon || $record->paymentAt() === null || $transaction->net_nominal === null) {
            return null;
        }

        return $record->netNominal;
    }

    /**
     * Tanggal Dibuat in WIB, and the last valid Tanggal Dibayar; a payment date that was never valid stays null
     * (Belum tersedia).
     *
     * @return array{created_at: string|null, paid_date: string|null, paid_time: string|null}
     */
    private function dates(Transaction $transaction): array
    {
        return [
            'created_at' => $this->wib($transaction->markaz_created_at),
            'paid_date' => $transaction->paid_date?->toDateString(),
            'paid_time' => $transaction->paid_date === null ? null : $this->blankToNull($transaction->paid_time),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupRow(Transaction $transaction, bool $withContact): array
    {
        return [
            'id' => $transaction->id,
            'trx_id' => $transaction->trx_id,
            'customer' => $this->customer($transaction, $withContact),
            'status' => $this->status($transaction),
            'is_claim_coupon' => $transaction->is_claim_coupon,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Transaction $transaction, bool $withContact): array
    {
        return [
            'name' => $transaction->customer_name,
            'markaz_customer_id' => $transaction->markaz_customer_id,
            ...($withContact ? [
                'email' => $this->blankToNull($transaction->customer_email),
                'phone' => $this->blankToNull($transaction->customer_phone),
            ] : []),
        ];
    }

    /**
     * The report status: effective_status when set, otherwise source_status; null code = Status belum tersedia.
     *
     * @return array{code: int|null, label: string}
     */
    private function status(Transaction $transaction): array
    {
        $status = $transaction->reportStatus();

        return ['code' => $status?->value, 'label' => MarkazTransactionStatus::labelFor($status)];
    }

    /**
     * @return array{slug: string, name: string}|null
     */
    private function platform(Transaction $transaction): ?array
    {
        return $transaction->platform === null ? null : ['slug' => $transaction->platform->slug, 'name' => $transaction->platform->name];
    }

    private function wib(?DateTimeInterface $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::parse($value->format('Y-m-d H:i:s'), TransactionRecord::TIMEZONE)->toIso8601String();
    }

    private function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function blankToNull(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
