<?php

namespace App\Services;

use App\Enums\TrendPeriod;
use App\Exceptions\ExportTooLargeException;
use App\Models\Platform;
use App\Support\PromoAnalysisFilter;
use App\Support\SpreadsheetWriter;

/**
 * Exports every Analisis Promo component for the active filter to Excel. Each sheet is built by the same service
 * as its endpoint, so the file always shows the numbers of the page; tables are complete, not one page.
 */
class PromoAnalysisExportService
{
    /**
     * Rows per worksheet in Excel, the header row included.
     */
    public const MAX_SHEET_ROWS = 1048576;

    private const KPI_LABELS = [
        'promo_transactions' => 'Total Transaksi dengan Promo',
        'non_promo_transactions' => 'Total Transaksi Tanpa Promo',
        'promo_revenue' => 'Revenue dengan Promo',
        'non_promo_revenue' => 'Revenue tanpa Promo',
        'promo_transaction_percentage' => 'Persentase Transaksi Pakai Promo (%)',
        'used_promo_codes' => 'Kode Promo Digunakan',
    ];

    public function __construct(
        private PromoAnalysisService $promoAnalysisService,
        private PromoCodeSummaryService $promoCodeSummaryService,
        private PromoTrendService $promoTrendService,
        private PromoProductTypeService $promoProductTypeService,
        private SpreadsheetWriter $spreadsheetWriter,
    ) {}

    /**
     * Write the export to a temporary file.
     *
     * @return array{path: string, filename: string}
     */
    public function export(PromoAnalysisFilter $filter, TrendPeriod $period, string $sort, string $direction): array
    {
        $usages = $this->promoCodeSummaryService->codeTotals($filter)['usages'];

        if ($usages + 1 > static::MAX_SHEET_ROWS) {
            throw ExportTooLargeException::rows('Detail Penggunaan', $usages, static::MAX_SHEET_ROWS - 1);
        }

        $path = tempnam(sys_get_temp_dir(), 'promo-analysis-export-');
        $this->spreadsheetWriter->xlsx($this->sections($filter, $period, $sort, $direction), $path);

        return [
            'path' => $path,
            'filename' => "promo-analisis_{$filter->period->from()->toDateString()}_{$filter->period->to()->toDateString()}.xlsx",
        ];
    }

    /**
     * Worksheet name => rows, the first row of each table being its header.
     *
     * @return array<string, iterable<list<string|int|float|null>>>
     */
    public function sections(PromoAnalysisFilter $filter, TrendPeriod $period, string $sort, string $direction): array
    {
        return [
            'Ringkasan' => $this->summaryRows($filter),
            'Funnel' => $this->funnelRows($filter),
            'Source' => $this->sourceRows($filter),
            'Rekap Kode Promo' => $this->codeRows($filter, $sort, $direction),
            'Detail Penggunaan' => $this->usageRows($filter),
            'Tren' => $this->trendRows($filter, $period),
            'Perdana vs Perpanjangan' => $this->productTypeRows($filter),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function summaryRows(PromoAnalysisFilter $filter): array
    {
        $summary = $this->promoAnalysisService->summarize($filter);
        $platforms = Platform::query()->whereIn('slug', $filter->period->platforms)->orderBy('id')->pluck('name')->all();

        $rows = [
            ['Periode', $this->periodText($summary['filter']['ranges'])],
            ['Periode Pembanding', $this->periodText($summary['previous_period']['ranges'])],
            ['Platform', $platforms === [] ? 'Semua platform' : implode(', ', $platforms)],
            ['Metode Pembayaran', $filter->paymentMethods === [] ? 'Semua metode pembayaran' : implode(', ', $filter->paymentMethods)],
            ['Pencarian Kode Promo', $summary['filter']['search'] ?? '-'],
            [],
            ['KPI', 'Nilai', 'Nilai Periode Pembanding', 'Perubahan (%)', 'Tren'],
        ];

        foreach (self::KPI_LABELS as $key => $label) {
            $kpi = $summary['kpis'][$key];
            $rows[] = [$label, $kpi['value'], $kpi['previous_value'], $kpi['change_percentage'], $this->trendText($kpi['trend'])];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function funnelRows(PromoAnalysisFilter $filter): array
    {
        return [
            ['Funnel', 'Jumlah Transaksi', 'Jumlah Revenue', 'Kontribusi Funnel (%)'],
            ...array_map(fn (array $row) => [
                $row['name'],
                $row['transactions'],
                $row['revenue'],
                $row['contribution_percentage'],
            ], $this->promoAnalysisService->funnelBreakdown($filter)['funnels']),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function sourceRows(PromoAnalysisFilter $filter): array
    {
        return [
            ['Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi Source (%)'],
            ...array_map(fn (array $row) => [
                $row['name'],
                $row['usages'],
                $row['revenue'],
                $row['contribution_percentage'],
            ], $this->promoAnalysisService->sourceBreakdown($filter)['sources']),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function codeRows(PromoAnalysisFilter $filter, string $sort, string $direction): array
    {
        return [
            ['Kode Promo', 'Kode Asli Markaz', 'Status', 'Platform', 'Funnel', 'Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi (%)'],
            ...$this->promoCodeSummaryService->allCodes($filter, $sort, $direction)->map(fn (array $row) => [
                $row['code'],
                implode(', ', $row['raw_codes']),
                $this->registrationText($row['is_registered']),
                $row['platform']['name'],
                $row['funnel']['name'] ?? null,
                $row['source']['name'] ?? null,
                $row['usages'],
                $row['revenue'],
                $row['contribution_percentage'],
            ])->all(),
        ];
    }

    /**
     * Streamed: one row per code usage, which can be many.
     *
     * @return iterable<list<string|int|float|null>>
     */
    private function usageRows(PromoAnalysisFilter $filter): iterable
    {
        yield ['Tanggal', 'Jam', 'ID Transaksi', 'Platform', 'Kode Promo', 'Kode Asli Markaz', 'Posisi Kode', 'Status', 'Funnel', 'Source', 'Jumlah Penggunaan', 'Revenue', 'Nominal Transaksi'];

        foreach ($this->promoCodeSummaryService->allUsages($filter) as $row) {
            yield [
                $row['paid_date'],
                $row['paid_time'],
                $row['trx_id'],
                $row['platform'],
                $row['code'],
                $row['raw_code'],
                $row['code_position'],
                $this->registrationText($row['is_registered']),
                $row['funnel'],
                $row['source'],
                1,
                $row['revenue'],
                $row['transaction_nominal'],
            ];
        }
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function trendRows(PromoAnalysisFilter $filter, TrendPeriod $period): array
    {
        $trend = $this->promoTrendService->trend($filter, $period);

        return [
            ['Periode', 'Tanggal Mulai', 'Tanggal Akhir', ...array_values(PromoTrendService::DATASETS)],
            ...array_map(fn (array $row) => [
                $row['label'],
                $row['period_start'],
                $row['period_end'],
                ...array_map(fn (string $key) => $row[$key], array_keys(PromoTrendService::DATASETS)),
            ], $trend['rows']),
        ];
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function productTypeRows(PromoAnalysisFilter $filter): array
    {
        return [
            ['Tipe Produk', 'Jumlah Transaksi Promo', 'Revenue Promo', 'Persentase Transaksi (%)', 'Persentase Revenue (%)'],
            ...array_map(fn (array $row) => [
                $row['name'],
                $row['transactions'],
                $row['revenue'],
                $row['transaction_percentage'],
                $row['revenue_percentage'],
            ], $this->promoProductTypeService->breakdown($filter)['product_types']),
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
            PromoAnalysisService::TREND_UP => 'Naik',
            PromoAnalysisService::TREND_DOWN => 'Turun',
            default => 'Sama',
        };
    }

    private function registrationText(bool $isRegistered): string
    {
        return $isRegistered ? 'Terdaftar' : 'Belum terdaftar';
    }
}
