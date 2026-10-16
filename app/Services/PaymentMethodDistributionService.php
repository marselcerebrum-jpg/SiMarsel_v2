<?php

namespace App\Services;

use App\Repositories\PaymentMethodDistributionRepository;
use App\Support\PerformanceFilter;

class PaymentMethodDistributionService
{
    public const UNKNOWN_METHOD = 'Tidak diketahui';

    public function __construct(private PaymentMethodDistributionRepository $paymentMethodDistributionRepository) {}

    /**
     * Each payment method's transactions, revenue, and share of the totals for the selected filters.
     *
     * @return array<string, mixed>
     */
    public function distribution(PerformanceFilter $filter): array
    {
        $methods = $this->paymentMethodDistributionRepository
            ->totalsPerPaymentMethod($filter)
            ->map(fn (object $row) => [
                'name' => filled($row->name) ? $row->name : self::UNKNOWN_METHOD,
                'transactions' => (int) $row->transactions,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->sortBy([['revenue', 'desc'], ['name', 'asc']])
            ->values();

        $totalTransactions = $methods->sum('transactions');
        $totalRevenue = round($methods->sum('revenue'), 2);

        $methods = $methods->map(fn (array $method) => [
            ...$method,
            'transaction_percentage' => $this->percentage($method['transactions'], $totalTransactions),
            'revenue_percentage' => $this->percentage($method['revenue'], $totalRevenue),
        ]);

        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'transactions' => $totalTransactions,
                'revenue' => $totalRevenue,
            ],
            'payment_methods' => $methods->all(),
            'labels' => $methods->pluck('name')->all(),
            'datasets' => [
                ['label' => 'Revenue', 'data' => $methods->pluck('revenue')->all()],
            ],
        ];
    }

    private function percentage(int|float $value, int|float $total): float|int
    {
        return $total > 0 ? round($value / $total * 100, 2) : 0;
    }
}
