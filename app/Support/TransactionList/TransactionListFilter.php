<?php

namespace App\Support\TransactionList;

use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use Carbon\CarbonImmutable;

/**
 * The rows of Daftar Transaksi: the main Dashboard period and platforms plus the local list filters.
 * The same filter selects the rows of the list and of its export, so both read the same set in the same order.
 */
final class TransactionListFilter
{
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const PROMO_WITH = 'with';

    public const PROMO_WITHOUT = 'without';

    /**
     * @param  list<int>  $platformIds  Ids of the selected platforms; every platform when the main filter has none.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges  Inclusive WIB date ranges of the main period.
     * @param  list<MarkazTransactionStatus>  $statuses  Report statuses (effective_status, then source_status).
     * @param  bool  $withUnavailableStatus  Also select rows without any status ("Status belum tersedia").
     * @param  list<string>  $products
     * @param  list<string>  $paymentMethods  Compared without case and surrounding spaces.
     * @param  list<string>  $productTypes
     * @param  list<string>  $promoCodes  Compared without case and surrounding spaces.
     */
    public function __construct(
        public readonly array $platformIds,
        public readonly array $ranges,
        public readonly TransactionDateBasis $dateBasis = TransactionDateBasis::Created,
        public readonly ?string $search = null,
        public readonly array $statuses = [],
        public readonly bool $withUnavailableStatus = false,
        public readonly array $products = [],
        public readonly array $paymentMethods = [],
        public readonly array $productTypes = [],
        public readonly array $promoCodes = [],
        public readonly ?string $promo = null,
    ) {}

    public function filtersStatus(): bool
    {
        return $this->statuses !== [] || $this->withUnavailableStatus;
    }
}
