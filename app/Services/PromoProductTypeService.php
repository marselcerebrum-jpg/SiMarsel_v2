<?php

namespace App\Services;

use App\Models\Transaction;
use App\Repositories\PromoAnalysisRepository;
use App\Support\PromoAnalysisFilter;
use Illuminate\Support\Collection;

/**
 * Promo transactions per product type. A future export of this table must call breakdown() so its numbers
 * always match the page.
 */
class PromoProductTypeService
{
    public const GROUP_FIRST_PURCHASE = 'first_purchase';

    public const GROUP_RENEWAL = 'renewal';

    public const GROUP_OTHER = 'other';

    public const GROUP_UNKNOWN = 'unknown';

    public const UNKNOWN = 'Tidak diketahui';

    public function __construct(private PromoAnalysisRepository $promoAnalysisRepository) {}

    /**
     * Unique promo transactions and their revenue per product type: Perdana and Perpanjangan always, then every
     * other product type as stored (A-Z), then Tidak diketahui. Shares are of the promo totals of the filter.
     *
     * @return array<string, mixed>
     */
    public function breakdown(PromoAnalysisFilter $filter): array
    {
        $rows = $this->promoAnalysisRepository->productTypeTotals($filter)->map(fn (object $row) => [
            'key' => $row->group_key,
            'name' => $row->name,
            'transactions' => (int) $row->transactions,
            'revenue' => (float) $row->revenue,
        ]);

        $group = fn (string $key, string $name, Collection $rows) => [
            'key' => $key,
            'name' => $name,
            'transactions' => $rows->sum('transactions'),
            'revenue' => round($rows->sum('revenue'), 2),
        ];

        $groups = collect([
            $group(self::GROUP_FIRST_PURCHASE, Transaction::TYPE_FIRST, $rows->where('key', self::GROUP_FIRST_PURCHASE)),
            $group(self::GROUP_RENEWAL, Transaction::TYPE_RENEWAL, $rows->where('key', self::GROUP_RENEWAL)),
            ...$rows->where('key', self::GROUP_OTHER)
                ->groupBy('name')
                ->sortKeys()
                ->map(fn (Collection $rows, string $name) => $group(self::GROUP_OTHER, $name, $rows))
                ->values(),
        ]);

        if ($rows->contains('key', self::GROUP_UNKNOWN)) {
            $groups->push($group(self::GROUP_UNKNOWN, self::UNKNOWN, $rows->where('key', self::GROUP_UNKNOWN)));
        }

        $totalTransactions = $rows->sum('transactions');
        $totalRevenue = round($rows->sum('revenue'), 2);
        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'transactions' => $totalTransactions,
                'revenue' => $totalRevenue,
            ],
            'product_types' => $groups->map(fn (array $row) => [
                ...$row,
                'transaction_percentage' => $totalTransactions > 0 ? round($row['transactions'] / $totalTransactions * 100, 2) : 0,
                'revenue_percentage' => $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 2) : 0,
            ])->all(),
        ];
    }
}
