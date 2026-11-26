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
        'unique_users' => 'Pembeli Unik',
        'new_users' => 'User Baru',
        'repeat_orders' => 'Repeat Order',
        'average_order_value' => 'AOV',
        'conversion_rate' => 'Konversi (%)',
    ];

    public function __construct(
        private PerformanceSummaryService $performanceSummaryService,
        private PerformanceTrendService $performanceTrendService,
        private DownloaderAggregationService $downloaderAggregationService,
        private PaymentMethodDistributionService $paymentMethodDistributionService,
        private PlatformContributionService $platformContributionService,
        private PurchaseTimeDistributionService $purchaseTimeDistributionService,
        private SpreadsheetWriter $spreadsheetWriter,
        private DashboardReportService $dashboardReportService,
    ) {}

    /**
     * Ekspor Laporan Dashboard (FIX-14-BE-01): one Excel workbook with the nine sheets of DashboardReportService.
     *
     * @return array{path: string, filename: string}
     */
    public function dashboardReport(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter, TrendPeriod $granularity, ?int $dayOfWeek): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dashboard-report-');

        $this->spreadsheetWriter->xlsx($this->dashboardReportService->sheets($filter, $downloaderFilter, $granularity, $dayOfWeek), $path);

        return [
            'path' => $path,
            'filename' => "laporan_dashboard_{$filter->from()->toDateString()}_{$filter->to()->toDateString()}.xlsx",
        ];
    }

    /**
     * Write the export to a temporary file.
     *
     * @param  'xlsx'|'csv'  $format
     * @param  PerformanceFilter|null  $downloaderFilter  The same preset resolved on the downloaders metadata.
     * @return array{path: string, filename: string}
     */
    public function export(PerformanceFilter $filter, string $format, ?PerformanceFilter $downloaderFilter = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'performance-export-');
        $sections = $this->sections($filter, $downloaderFilter);

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
     * The Downloader sheet is added when the shared period resolver chose the period.
     *
     * @return array<string, list<list<string|int|float|null>>>
     */
    public function sections(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter = null): array
    {
        return [
            'Ringkasan KPI' => $this->summaryRows($filter, $downloaderFilter),
            'Tren' => $this->trendRows($filter),
            ...($downloaderFilter?->period === null ? [] : ['Downloader' => $this->downloaderRows($downloaderFilter)]),
            'Metode Pembayaran' => $this->paymentMethodRows($filter),
            'Kontribusi Platform' => $this->platformContributionRows($filter),
            'Waktu Pembelian' => $this->purchaseTimeRows($filter),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function summaryRows(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter): array
    {
        $summary = $this->performanceSummaryService->summarize($filter, $downloaderFilter);

        $rows = [
            ['Periode', $this->periodText($summary['filter']['ranges'])],
            ['Periode Sebelumnya', $this->periodText($summary['previous_period']['ranges'])],
            ['Platform', $filter->platforms === [] ? 'Semua platform' : implode(', ', $filter->platforms)],
            ...$this->periodLimitRows($filter),
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
     * The data limits and the part of each period used for the change, when the shared resolver chose the period.
     *
     * @return list<list<string>>
     */
    private function periodLimitRows(PerformanceFilter $filter): array
    {
        if ($filter->period === null) {
            return [];
        }

        $period = $filter->period->toArray();
        $limit = fn (?array $window) => $window['cutoff_at'] ?? 'Belum tersedia';
        $rows = [
            ['Batas Data Aktif', $limit($period['active'])],
            ['Batas Data Pembanding', $limit($period['comparison'])],
        ];

        if ($period['change_basis']['is_trimmed'] ?? false) {
            $rows[] = ['Dasar Perubahan Aktif', $this->periodText($period['change_basis']['active'])];
            $rows[] = ['Dasar Perubahan Pembanding', $this->periodText($period['change_basis']['comparison'])];
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
            ...array_map(fn (TrendMetric $metric) => $metric === TrendMetric::Conversion ? 'Konversi (%)' : $metric->label(), $metrics),
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
     * The Lihat Rincian rows of the Downloader chart at its default granularity, then the period totals and the
     * platform shares. An unknown value is written as Belum tersedia; a stored 0 stays 0.
     *
     * @return list<list<string|int|float|null>>
     */
    private function downloaderRows(PerformanceFilter $filter): array
    {
        $trend = $this->downloaderAggregationService->trend($filter, TrendPeriod::defaultFor($filter->period));
        $value = fn (?array $side) => $side['value'] ?? 'Belum tersedia';
        $period = fn (?array $side) => $side === null ? 'Belum tersedia' : $side['label'];

        $rows = [['Periode Aktif', 'Downloader', 'Periode Pembanding', 'Downloader Pembanding', 'Perubahan (%)']];

        foreach ($trend['buckets'] as $bucket) {
            $rows[] = [
                $period($bucket['active']),
                $value($bucket['active']),
                $period($bucket['comparison']),
                $value($bucket['comparison']),
                $bucket['change']['change_percentage'],
            ];
        }

        $rows[] = [];
        $rows[] = ['Total', $value($trend['totals']['active']), '', $value($trend['totals']['comparison']), $trend['totals']['change']['change_percentage']];
        $rows[] = [];
        $rows[] = ['Platform', 'Downloader', 'Persentase (%)'];

        foreach ($trend['platforms'] as $platform) {
            $rows[] = [$platform['name'], $value($platform), $platform['share_percentage'] ?? 'N/A'];
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
            ['Peringkat', 'Platform', 'Revenue', 'Transaksi', 'Pembeli Unik', 'Persentase Kontribusi (%)'],
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

    private function trendText(?string $trend): string
    {
        return match ($trend) {
            null => 'Tanpa Pembanding',
            PerformanceSummaryService::TREND_UP => 'Naik',
            PerformanceSummaryService::TREND_DOWN => 'Turun',
            default => 'Sama',
        };
    }
}
