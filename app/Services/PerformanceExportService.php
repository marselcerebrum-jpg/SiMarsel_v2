<?php

namespace App\Services;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Support\PerformanceFilter;
use App\Support\SpreadsheetWriter;

/**
 * Exports the Ringkasan Performa widgets for the active filter, using the same services as the dashboard
 * so the file always shows the same numbers.
 */
class PerformanceExportService
{
    private const KPI_LABELS = [
        'total_revenue' => 'Total Revenue',
        'total_transactions' => 'Total Transaksi',
        'total_downloaders' => 'Total Downloader',
        'unique_users' => 'User Unik',
        'new_users' => 'User Baru',
        'repeat_orders' => 'Repeat Order',
        'average_order_value' => 'AOV',
        'conversion_rate' => 'Konversi (%)',
    ];

    public function __construct(
        private PerformanceSummaryService $performanceSummaryService,
        private PerformanceTrendService $performanceTrendService,
        private PaymentMethodDistributionService $paymentMethodDistributionService,
        private PlatformContributionService $platformContributionService,
        private PurchaseTimeDistributionService $purchaseTimeDistributionService,
        private SpreadsheetWriter $spreadsheetWriter,
    ) {}

    /**
     * Write the export to a temporary file.
     *
     * @param  'xlsx'|'csv'  $format
     * @return array{path: string, filename: string}
     */
    public function export(PerformanceFilter $filter, string $format): array
    {
        $path = tempnam(sys_get_temp_dir(), 'performance-export-');
        $sections = $this->sections($filter);

        match ($format) {
            'xlsx' => $this->spreadsheetWriter->xlsx($sections, $path),
            'csv' => $this->spreadsheetWriter->csv($sections, $path),
        };

        return [
            'path' => $path,
            'filename' => "performa_{$filter->from()->toDateString()}_{$filter->to()->toDateString()}.{$format}",
        ];
    }

    /**
     * Section title => rows, the first row of each table being its header.
     *
     * @return array<string, list<list<string|int|float|null>>>
     */
    public function sections(PerformanceFilter $filter): array
    {
        return [
            'Ringkasan KPI' => $this->summaryRows($filter),
            'Tren' => $this->trendRows($filter),
            'Metode Pembayaran' => $this->paymentMethodRows($filter),
            'Kontribusi Platform' => $this->platformContributionRows($filter),
            'Waktu Pembelian' => $this->purchaseTimeRows($filter),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function summaryRows(PerformanceFilter $filter): array
    {
        $summary = $this->performanceSummaryService->summarize($filter);

        $rows = [
            ['Periode', $this->periodText($summary['filter']['ranges'])],
            ['Periode Sebelumnya', $this->periodText($summary['previous_period']['ranges'])],
            ['Platform', $filter->platforms === [] ? 'Semua platform' : implode(', ', $filter->platforms)],
            [],
            ['KPI', 'Nilai', 'Nilai Periode Sebelumnya', 'Perubahan (%)', 'Tren'],
        ];

        foreach (self::KPI_LABELS as $key => $label) {
            $kpi = $summary['kpis'][$key];
            $rows[] = [$label, $kpi['value'], $kpi['previous_value'], $kpi['change_percentage'], $this->trendText($kpi['trend'])];
        }

        return $rows;
    }

    /**
     * Every trend metric side by side. A daily export longer than the daily chart limit is grouped per month.
     *
     * @return list<list<string|int|float|null>>
     */
    private function trendRows(PerformanceFilter $filter): array
    {
        $period = $filter->defaultPeriod;

        if ($period->maxDays() !== null && $filter->days() > $period->maxDays()) {
            $period = TrendPeriod::Monthly;
        }

        $metrics = TrendMetric::cases();
        $trends = array_map(fn (TrendMetric $metric) => $this->performanceTrendService->trend($filter, $metric, $period), $metrics);

        $rows = [[
            'Periode',
            'Tanggal Mulai',
            'Tanggal Akhir',
            ...array_map(fn (TrendMetric $metric) => $metric === TrendMetric::Conversion ? 'Conversion (%)' : $metric->label(), $metrics),
        ]];

        foreach ($trends[0]['rows'] as $index => $row) {
            $rows[] = [
                $row['label'],
                $row['period_start'],
                $row['period_end'],
                ...array_map(fn (array $trend) => $trend['rows'][$index]['value'], $trends),
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function paymentMethodRows(PerformanceFilter $filter): array
    {
        $distribution = $this->paymentMethodDistributionService->distribution($filter);

        return [
            ['Metode Pembayaran', 'Jumlah Transaksi', 'Revenue', 'Persentase Transaksi (%)', 'Persentase Kontribusi (%)'],
            ...array_map(fn (array $method) => [
                $method['name'],
                $method['transactions'],
                $method['revenue'],
                $method['transaction_percentage'],
                $method['revenue_percentage'],
            ], $distribution['payment_methods']),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function platformContributionRows(PerformanceFilter $filter): array
    {
        $contribution = $this->platformContributionService->contribution($filter);

        return [
            ['Peringkat', 'Platform', 'Revenue', 'Transaksi', 'User', 'Persentase Kontribusi (%)'],
            ...array_map(fn (array $platform) => [
                $platform['rank'],
                $platform['name'],
                $platform['revenue'],
                $platform['transactions'],
                $platform['users'],
                $platform['contribution_percentage'] ?? 'N/A',
            ], $contribution['platforms']),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function purchaseTimeRows(PerformanceFilter $filter): array
    {
        $distribution = $this->purchaseTimeDistributionService->distribution($filter);

        return [
            ['Rentang Waktu', 'Jam Mulai', 'Jam Akhir', 'Jumlah Transaksi', 'Persentase (%)'],
            ...array_map(fn (array $range) => [
                $range['label'],
                $range['start'],
                $range['end'],
                $range['transactions'],
                $range['percentage'],
            ], $distribution['time_ranges']),
        ];
    }

    /**
     * @param  list<array{date_from: string, date_to: string}>  $ranges
     */
    private function periodText(array $ranges): string
    {
        return implode(', ', array_map(fn (array $range) => "{$range['date_from']} s/d {$range['date_to']}", $ranges));
    }

    private function trendText(string $trend): string
    {
        return match ($trend) {
            PerformanceSummaryService::TREND_UP => 'Naik',
            PerformanceSummaryService::TREND_DOWN => 'Turun',
            default => 'Sama',
        };
    }
}
