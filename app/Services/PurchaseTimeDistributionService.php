<?php

namespace App\Services;

use App\Repositories\PurchaseTimeDistributionRepository;
use Carbon\CarbonImmutable;

class PurchaseTimeDistributionService
{
    /**
     * Purchase time ranges by hour of the day, from the first hour to the last hour (inclusive).
     */
    public const TIME_RANGES = [
        ['key' => 'dawn', 'label' => 'Dini Hari', 'start' => 0, 'end' => 5],
        ['key' => 'morning', 'label' => 'Pagi', 'start' => 6, 'end' => 10],
        ['key' => 'midday', 'label' => 'Siang', 'start' => 11, 'end' => 14],
        ['key' => 'afternoon', 'label' => 'Sore', 'start' => 15, 'end' => 17],
        ['key' => 'night', 'label' => 'Malam', 'start' => 18, 'end' => 23],
    ];

    public const UNKNOWN_RANGE = 'Tidak diketahui';

    public function __construct(private PurchaseTimeDistributionRepository $purchaseTimeDistributionRepository) {}

    /**
     * Successful transactions per purchase time range for the selected filters.
     *
     * @return array<string, mixed>
     */
    public function distribution(CarbonImmutable $from, CarbonImmutable $to, ?string $platformSlug): array
    {
        $rows = $this->purchaseTimeDistributionRepository->transactionsPerHour($from, $to, $platformSlug);

        $perHour = $rows->whereNotNull('hour')
            ->mapWithKeys(fn (object $row) => [(int) $row->hour => (int) $row->transactions]);
        $withoutTime = (int) $rows->whereNull('hour')->sum('transactions');

        $ranges = collect(self::TIME_RANGES)->map(fn (array $range) => [
            'key' => $range['key'],
            'label' => $range['label'],
            'start' => sprintf('%02d:00', $range['start']),
            'end' => sprintf('%02d:59', $range['end']),
            'transactions' => $perHour
                ->filter(fn (int $count, int $hour) => $hour >= $range['start'] && $hour <= $range['end'])
                ->sum(),
        ]);

        // Transactions without a purchase time are still counted so the total matches the KPI.
        if ($withoutTime > 0) {
            $ranges->push([
                'key' => 'unknown',
                'label' => self::UNKNOWN_RANGE,
                'start' => null,
                'end' => null,
                'transactions' => $withoutTime,
            ]);
        }

        $totalTransactions = $ranges->sum('transactions');

        $ranges = $ranges->map(fn (array $range) => [
            ...$range,
            'percentage' => $totalTransactions > 0
                ? round($range['transactions'] / $totalTransactions * 100, 1)
                : 0,
        ]);

        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'platform' => $platformSlug,
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'transactions' => $totalTransactions,
            ],
            'time_ranges' => $ranges->all(),
            'labels' => $ranges->map(fn (array $range) => $range['start'] === null
                ? $range['label']
                : "{$range['label']} ({$range['start']} - {$range['end']})")->all(),
            'datasets' => [
                ['label' => 'Transaksi', 'data' => $ranges->pluck('transactions')->all()],
            ],
        ];
    }
}
