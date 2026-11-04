<?php

namespace App\Services;

use App\Enums\TrendPeriod;
use App\Repositories\PromoAnalysisRepository;
use App\Support\PromoAnalysisFilter;
use App\Support\TrendBuckets;
use Carbon\CarbonImmutable;

class PromoTrendService
{
    /**
     * Chart datasets: the promo metrics and the total sales they are compared with.
     */
    public const DATASETS = [
        'promo_transactions' => 'Transaksi dengan Promo',
        'promo_revenue' => 'Revenue dengan Promo',
        'total_transactions' => 'Total Transaksi',
        'total_sales' => 'Total Penjualan',
    ];

    public function __construct(private PromoAnalysisRepository $promoAnalysisRepository) {}

    /**
     * Promo transactions and revenue per daily, weekly, or monthly bucket of paid_date, with the total sales of
     * the same buckets as an overlay. Every transaction counts once, also with two codes. Every dataset uses the
     * same active filter as the KPI summary, including the promo code search, and like every promo analysis it
     * excludes anomalous transactions. Buckets that start after today have no data yet, so their values are null.
     *
     * @return array<string, mixed>
     */
    public function trend(PromoAnalysisFilter $filter, TrendPeriod $period): array
    {
        $buckets = TrendBuckets::forFilter($filter->period, $period);
        $bucketOfDay = TrendBuckets::bucketOfDay($buckets);
        $values = array_fill_keys(array_keys(self::DATASETS), array_fill(0, count($buckets), 0));

        foreach ($this->promoAnalysisRepository->dailyTransactionTotals($filter) as $row) {
            $bucket = $bucketOfDay[$this->date($row->day)];
            $values['promo_transactions'][$bucket] += (int) $row->promo_transactions;
            $values['promo_revenue'][$bucket] += (float) $row->promo_revenue;
            $values['total_transactions'][$bucket] += (int) $row->transactions;
            $values['total_sales'][$bucket] += (float) $row->revenue;
        }

        $totals = array_map(fn (array $data) => round(array_sum($data), 2), $values);
        $totals['promo_transactions'] = (int) $totals['promo_transactions'];
        $totals['total_transactions'] = (int) $totals['total_transactions'];

        $today = CarbonImmutable::today();
        $values = array_map(fn (array $data) => array_map(
            fn (int|float $value, array $bucket) => $bucket['start']->gt($today) ? null : (is_float($value) ? round($value, 2) : $value),
            $data,
            $buckets,
        ), $values);

        $isEmpty = $totals['total_transactions'] === 0;

        return [
            'filter' => $filter->toArray(),
            'period' => $period->value,
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => $totals,
            'labels' => array_column($buckets, 'label'),
            'datasets' => array_map(
                fn (string $key) => ['key' => $key, 'label' => self::DATASETS[$key], 'data' => $values[$key]],
                array_keys(self::DATASETS),
            ),
            'rows' => array_map(fn (int $index) => [
                'label' => $buckets[$index]['label'],
                'period_start' => $buckets[$index]['start']->toDateString(),
                'period_end' => $buckets[$index]['end']->toDateString(),
                ...array_map(fn (array $data) => $data[$index], $values),
            ], array_keys($buckets)),
        ];
    }

    /**
     * DATE(paid_date) comes back as "Y-m-d" or "Y-m-d H:i:s" depending on the database driver.
     */
    private function date(string $value): string
    {
        return substr($value, 0, 10);
    }
}
