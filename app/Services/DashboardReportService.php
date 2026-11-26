<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use App\Enums\TrendPeriod;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use Carbon\CarbonImmutable;

/**
 * Laporan Dashboard (FIX-14-BE-01): the nine sheets of the dashboard workbook. Every number is read from the same
 * component dataset the screen reads, with the same filter, cutoff, granularity, and day choice; this class only lays
 * the datasets out. Active, comparison, and change values are separate columns. A value that is not available yet is
 * an empty cell next to an availability column that says so, while a known 0 stays 0. Every series is written, also
 * when the screen hides it.
 */
class DashboardReportService
{
    /** The sheets in the order of the ticket. */
    public const SHEETS = [
        'Ringkasan Sales',
        'Tren dan Performa',
        'Kontribusi Platform',
        'Downloader',
        'Status Transaksi',
        'Metode Pembayaran',
        'Performa Produk',
        'Jam Ramai Pembelian',
        'Perdana vs Perpanjangan',
    ];

    public const UNAVAILABLE = 'Belum tersedia';

    private const AVAILABILITY_LABELS = [
        'complete' => 'Lengkap',
        'partial' => 'Sebagian',
        'unavailable' => self::UNAVAILABLE,
    ];

    private const GRANULARITY_LABELS = [
        'hourly' => 'Per Jam',
        'daily' => 'Harian',
        'weekly' => 'Mingguan',
        'monthly' => 'Bulanan',
    ];

    private const UNIT_LABELS = [
        'currency' => 'Rupiah',
        'count' => 'Jumlah',
        'percentage' => 'Persen (%)',
        'percent' => 'Persen (%)',
        'percentage_point' => 'Poin persen',
    ];

    private const DATE_BASIS_LABELS = [
        'paid' => 'Tanggal bayar',
        'created' => 'Tanggal transaksi dibuat',
    ];

    public function __construct(
        private PerformanceSummaryService $performanceSummaryService,
        private PerformanceTrendService $performanceTrendService,
        private DownloaderAggregationService $downloaderAggregationService,
        private PlatformContributionService $platformContributionService,
        private TransactionCompositionService $transactionCompositionService,
        private PaymentMethodComparisonService $paymentMethodComparisonService,
        private ProductPerformanceService $productPerformanceService,
        private PeakPurchaseHourService $peakPurchaseHourService,
    ) {}

    /**
     * Sheet title => rows, in the order of SHEETS.
     *
     * @param  PerformanceFilter  $filter  The main filter resolved by the shared period resolver.
     * @param  PerformanceFilter|null  $downloaderFilter  The same selection resolved on the downloaders metadata.
     * @return array<string, list<list<string|int|float|null>>>
     */
    public function sheets(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter, TrendPeriod $granularity, ?int $dayOfWeek, ?CarbonImmutable $generatedAt = null): array
    {
        $period = $filter->period ?? throw new \InvalidArgumentException('The dashboard report needs a resolved period.');
        $hourly = $this->peakPurchaseHourService->hourly($period, $dayOfWeek);

        return [
            'Ringkasan Sales' => $this->salesSummaryRows($filter, $downloaderFilter, $granularity, $hourly, $generatedAt ?? CarbonImmutable::now('Asia/Jakarta')),
            'Tren dan Performa' => $this->trendRows($filter, $granularity),
            'Kontribusi Platform' => $this->platformRows($filter),
            'Downloader' => $this->downloaderRows($downloaderFilter, $granularity),
            'Status Transaksi' => $this->statusRows($period),
            'Metode Pembayaran' => $this->paymentMethodRows($period),
            'Performa Produk' => $this->productRows($period),
            'Jam Ramai Pembelian' => $this->hourlyRows($hourly),
            'Perdana vs Perpanjangan' => $this->productTypeRows($period),
        ];
    }

    /**
     * The report metadata, then the seven Ringkasan Sales metrics.
     *
     * @param  array<string, mixed>  $hourly
     * @return list<list<string|int|float|null>>
     */
    private function salesSummaryRows(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter, TrendPeriod $granularity, array $hourly, CarbonImmutable $generatedAt): array
    {
        $period = $filter->period;
        $summary = $this->performanceSummaryService->summarize($filter, $downloaderFilter)['sales_summary'];

        $rows = [
            ['Laporan', 'Laporan Dashboard Analisis Performa'],
            ['Dibuat', $generatedAt->setTimezone('Asia/Jakarta')->toIso8601String()],
            ['Zona Waktu', 'WIB (Asia/Jakarta)'],
            ['Preset', $period->preset->value],
            ['Platform', $period->platforms === [] ? 'Semua platform' : implode(', ', $period->platforms)],
            ...$this->windowRows('Aktif', $period->active),
            ...($period->comparison === null
                ? [['Periode Pembanding', 'Tidak ada pembanding']]
                : $this->windowRows('Pembanding', $period->comparison)),
            ...$this->changeBasisRows($period),
            ['Pengelompokan Tren', self::GRANULARITY_LABELS[$granularity->value]],
            ['Pilihan Hari Jam Ramai', $hourly['day_label']],
            ['Dasar Tanggal', 'Omzet, Transaksi Berhasil, Rata-rata Nilai Order, dan Pembeli Unik memakai tanggal bayar. Total Transaksi, Konversi, status, metode pembayaran, dan produk memakai tanggal transaksi dibuat.'],
            ['Keterangan Nilai', 'Sel nilai kosong berarti data belum tersedia (lihat kolom Ketersediaan). 0 berarti data lengkap tanpa transaksi.'],
            [],
            [
                'Metrik', 'Dasar Tanggal', 'Unit',
                'Nilai Aktif', 'Ketersediaan Aktif', 'Batas Data Aktif', 'Sumber Aktif',
                'Nilai Pembanding', 'Ketersediaan Pembanding', 'Batas Data Pembanding', 'Sumber Pembanding',
                'Nilai Aktif untuk Perubahan', 'Nilai Pembanding untuk Perubahan', 'Perubahan', 'Unit Perubahan',
                'Label Konversi', 'Status Belum Tersedia',
            ],
        ];

        foreach ($summary['metrics'] as $metric) {
            $active = $metric['active'];
            $comparison = $metric['comparison'] ?? null;
            $change = $metric['change'] ?? [];

            $rows[] = [
                $metric['label'],
                self::DATE_BASIS_LABELS[$metric['date_basis'] ?? ''] ?? '-',
                self::UNIT_LABELS[$metric['unit']] ?? $metric['unit'],
                $active['value'],
                $this->availability($active['availability']),
                $active['as_of'],
                $this->source($active['source'] ?? null),
                $comparison['value'] ?? null,
                $comparison === null ? 'Tidak ada pembanding' : $this->availability($comparison['availability']),
                $comparison['as_of'] ?? null,
                $this->source($comparison['source'] ?? null),
                $change['active_value'] ?? null,
                $change['comparison_value'] ?? null,
                $change['value'] ?? null,
                self::UNIT_LABELS[$change['unit'] ?? ''] ?? null,
                $active['provisional_label'] ?? null,
                $metric['key'] === 'conversion' ? ($active['unknown_status_count'] ?? null) : null,
            ];
        }

        return $rows;
    }

    /**
     * Every bucket at the chosen granularity with the three series of both sides, visible or not on the screen.
     *
     * @return list<list<string|int|float|null>>
     */
    private function trendRows(PerformanceFilter $filter, TrendPeriod $granularity): array
    {
        $trend = $this->performanceTrendService->salesTrend($filter, $granularity);
        $values = fn (?array $side) => [
            $side['values']['revenue'] ?? null,
            $side['values']['success_payments'] ?? null,
            $side['values']['total_checkouts'] ?? null,
        ];

        $rows = [
            ['Pengelompokan', self::GRANULARITY_LABELS[$trend['granularity']] ?? $trend['granularity']],
            ['Seri', 'Semua seri ditulis: Omzet dan Transaksi Berhasil (tanggal bayar), Total Transaksi (tanggal dibuat), aktif dan pembanding.'],
            [],
            [
                'Bucket Aktif', 'Mulai Aktif', 'Akhir Aktif', 'Ketersediaan Aktif', 'Batas Data Aktif',
                'Omzet', 'Transaksi Berhasil', 'Total Transaksi',
                'Bucket Pembanding', 'Mulai Pembanding', 'Akhir Pembanding', 'Ketersediaan Pembanding', 'Sumber Pembanding',
                'Omzet Pembanding', 'Transaksi Berhasil Pembanding', 'Total Transaksi Pembanding',
            ],
        ];

        foreach ($trend['buckets'] as $bucket) {
            $active = $bucket['active'];
            $comparison = $bucket['comparison'];

            $rows[] = [
                $active['label'], $active['start_at'], $active['end_at'], $this->availability($active['availability']), $active['as_of'],
                ...$values($active),
                $comparison['label'] ?? null, $comparison['start_at'] ?? null, $comparison['end_at'] ?? null,
                $comparison === null ? 'Tidak ada pembanding' : $this->availability($comparison['availability']),
                $this->source($comparison['source'] ?? null),
                ...$values($comparison),
            ];
        }

        $rows[] = [];
        $rows[] = ['Total', null, null, $this->availability($trend['totals']['availability']), $trend['totals']['as_of'], ...$values($trend['totals'])];

        return $rows;
    }

    /**
     * Both modes of Kontribusi Platform (transactions and revenue), the status breakdown, the contribution, and the
     * comparison revenue, from the shared per-platform dataset.
     *
     * @return list<list<string|int|float|null>>
     */
    private function platformRows(PerformanceFilter $filter): array
    {
        $metrics = $this->platformContributionService->transactionMetrics($filter);
        $statusKeys = ['success', 'pending', 'unsuccessful', 'claim_coupon', 'unknown'];

        $rows = [[
            'Peringkat', 'Platform',
            'Total Transaksi', 'Transaksi Berhasil (tanggal dibuat)', 'Konversi (%)', 'Label Konversi',
            'Success', 'Pending', 'Tidak Berhasil', 'Klaim Kupon', 'Status Belum Tersedia',
            'Transaksi Berhasil (tanggal bayar)', 'Omzet', 'Omzet Pembanding', 'Kontribusi (%)',
            'Downloader', 'Ketersediaan Downloader', 'Success per Downloader (%)',
        ]];

        foreach ($metrics['platforms'] as $platform) {
            $breakdown = array_column($platform['status_breakdown'], 'count', 'key');

            $rows[] = [
                $platform['rank'],
                $platform['name'],
                $platform['total_transactions'],
                $platform['created_success_transactions'],
                $platform['conversion_percentage'],
                $platform['conversion_is_provisional'] ? 'Sementara' : null,
                ...array_map(fn (string $key) => $breakdown[$key] ?? null, $statusKeys),
                $platform['success_payments'],
                $platform['revenue'],
                $platform['revenue_comparison'],
                $platform['contribution_percentage'],
                $platform['downloaders'],
                $this->availability($platform['downloaders_availability']),
                $platform['success_per_downloader_percentage'],
            ];
        }

        $totals = $metrics['totals'];
        $rows[] = [];
        $rows[] = [
            'Total', null,
            $totals['total_transactions'], $totals['created_success_transactions'], null, null,
            null, null, null, null, null,
            $totals['success_payments'], $totals['revenue'], $totals['revenue_comparison'], null,
            $totals['downloaders'], null, null,
        ];

        return $rows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function downloaderRows(?PerformanceFilter $filter, TrendPeriod $granularity): array
    {
        if ($filter?->period === null) {
            return [['Downloader', self::UNAVAILABLE]];
        }

        $trend = $this->downloaderAggregationService->trend($filter, $granularity);
        $side = fn (?array $value, string $missing) => $value === null
            ? [null, null, $missing, null, null]
            : [$value['label'] ?? null, $value['value'], $this->availability($value['availability']), $value['as_of'], $this->source($value['source'] ?? null)];

        $rows = [
            ['Pengelompokan', self::GRANULARITY_LABELS[$trend['granularity']] ?? $trend['granularity']],
            [],
            [
                'Periode Aktif', 'Downloader', 'Ketersediaan Aktif', 'Batas Data Aktif', 'Sumber Aktif',
                'Periode Pembanding', 'Downloader Pembanding', 'Ketersediaan Pembanding', 'Batas Data Pembanding', 'Sumber Pembanding',
                'Perubahan (%)', 'Dapat Dibandingkan',
            ],
        ];

        foreach ($trend['buckets'] as $bucket) {
            $rows[] = [
                ...$side($bucket['active'], self::UNAVAILABLE),
                ...$side($bucket['comparison'], 'Tidak ada pembanding'),
                $bucket['change']['change_percentage'],
                $bucket['change']['is_comparable'] ? 'Ya' : 'Tidak',
            ];
        }

        $totals = $trend['totals'];
        $rows[] = [];
        $rows[] = [
            'Total', ...array_slice($side($totals['active'], self::UNAVAILABLE), 1),
            null, ...array_slice($side($totals['comparison'], 'Tidak ada pembanding'), 1),
            $totals['change']['change_percentage'],
            $totals['change']['is_comparable'] ? 'Ya' : 'Tidak',
        ];
        $rows[] = [];
        $rows[] = ['Platform', 'Downloader', 'Ketersediaan', 'Persentase (%)'];

        foreach ($trend['platforms'] as $platform) {
            $rows[] = [$platform['name'], $platform['value'], $this->availability($platform['availability']), $platform['share_percentage']];
        }

        return $rows;
    }

    /**
     * The five statuses, the Tidak Berhasil subtotal, and the checkouts without a status, with the purchase and claim
     * breakdown of each.
     *
     * @return list<list<string|int|float|null>>
     */
    private function statusRows(ResolvedPeriod $period): array
    {
        $dataset = $this->transactionCompositionService->statusDistribution($period);
        $side = fn (?array $values) => [
            $values['count'] ?? null,
            $values['purchase_count'] ?? null,
            $values['claim_count'] ?? null,
            $values['share_percentage'] ?? null,
            $values['nominal'] ?? null,
        ];

        $rows = [
            ['Dasar Tanggal', self::DATE_BASIS_LABELS['created']],
            ...$this->sideMetaRows($dataset),
            [],
            [
                'Status', 'Jumlah', 'Pembelian', 'Klaim', 'Porsi (%)', $dataset['nominal_label'],
                'Jumlah Pembanding', 'Pembelian Pembanding', 'Klaim Pembanding', 'Porsi Pembanding (%)', $dataset['nominal_label'].' Pembanding',
                'Perubahan Porsi (poin persen)',
            ],
        ];

        foreach ([...$dataset['statuses'], $dataset['unsuccessful'], $dataset['unknown_status']] as $item) {
            $rows[] = [
                $item['key'] === 'unsuccessful' ? 'Subtotal Tidak Berhasil (Expired + Canceled + Failed)' : $item['label'],
                ...$side($item['active']),
                ...$side($item['comparison']),
                $item['share_change_percentage_point'],
            ];
        }

        $totals = $dataset['active']['totals'];
        $rows[] = [];
        $rows[] = ['Total Checkout', $totals['total_checkouts'], $totals['purchase_attempts'], $totals['claim_checkouts']];

        return $rows;
    }

    /**
     * The seven columns of the per-method table, with the comparison, the change, the claims, and the purchases
     * without a status.
     *
     * @return list<list<string|int|float|null>>
     */
    private function paymentMethodRows(ResolvedPeriod $period): array
    {
        $comparison = $this->paymentMethodComparisonService->compare($period);
        $keys = ['total_transactions', 'success_purchases', 'pending_purchases', 'unsuccessful_purchases', 'conversion', 'revenue'];
        $labels = array_column($comparison['columns'], 'label', 'key');

        $header = [$labels['payment_method'] ?? 'Metode Pembayaran'];
        foreach ($keys as $key) {
            $header[] = $labels[$key] ?? $key;
            $header[] = ($labels[$key] ?? $key).' Pembanding';
            $header[] = 'Perubahan '.($labels[$key] ?? $key);
            $header[] = 'Unit Perubahan '.($labels[$key] ?? $key);
        }

        $rows = [
            ['Ketersediaan Aktif', $this->availability($comparison['active']['availability'] ?? null), $comparison['active']['as_of'] ?? null],
            ['Ketersediaan Pembanding', $this->availability($comparison['comparison']['availability'] ?? null), $comparison['comparison']['as_of'] ?? null, $comparison['comparison']['message'] ?? null],
            ['Dasar Perubahan Dipotong', ($comparison['change_basis']['is_trimmed'] ?? false) ? 'Ya' : 'Tidak'],
            ...($comparison['message'] === null ? [] : [['Keterangan', $comparison['message']]]),
            [],
            [...$header, 'Klaim Kupon', 'Percobaan Pembelian', 'Status Belum Tersedia', 'Label Konversi', 'Expired', 'Canceled', 'Failed'],
        ];

        foreach ($comparison['rows'] as $row) {
            $cells = [$row['name']];

            foreach ($keys as $key) {
                $cells[] = $row['active'][$key] ?? null;
                $cells[] = $row['comparison'][$key] ?? null;
                $cells[] = $row['changes'][$key]['value'] ?? null;
                $cells[] = self::UNIT_LABELS[$row['changes'][$key]['unit'] ?? ''] ?? null;
            }

            $rows[] = [
                ...$cells,
                $row['active']['claim_checkouts'] ?? null,
                $row['active']['purchase_attempts'] ?? null,
                $row['active']['unknown_status_count'] ?? null,
                $row['active']['conversion_label'] ?? null,
                $row['active']['unsuccessful_breakdown']['expired'] ?? null,
                $row['active']['unsuccessful_breakdown']['canceled'] ?? null,
                $row['active']['unsuccessful_breakdown']['failed'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Every product of the main filter: the eight table columns, then the Expired, Canceled, Failed, Klaim Kupon, and
     * status-unavailable breakdowns.
     *
     * @return list<list<string|int|float|null>>
     */
    private function productRows(ResolvedPeriod $period): array
    {
        $dataset = $this->productPerformanceService->all($period);
        $columns = $dataset['columns'];

        $rows = [
            ['Ketersediaan Aktif', $this->availability($dataset['active']['availability'] ?? null), $dataset['active']['as_of'] ?? null],
            ...($dataset['message'] === null ? [] : [['Keterangan', $dataset['message']]]),
            [],
            [
                ...array_column($columns, 'label'),
                'Platform', 'Expired', 'Canceled', 'Failed', 'Klaim Kupon', 'Percobaan Pembelian', 'Status Belum Tersedia',
            ],
        ];

        foreach ($dataset['rows'] as $row) {
            $rows[] = [
                ...array_map(fn (array $column) => $row[$column['key']] ?? null, $columns),
                $row['platform']['name'] ?? null,
                $row['unsuccessful_breakdown']['expired'] ?? null,
                $row['unsuccessful_breakdown']['canceled'] ?? null,
                $row['unsuccessful_breakdown']['failed'] ?? null,
                $row['total_breakdown']['claim_checkouts'] ?? null,
                $row['total_breakdown']['purchase_attempts'] ?? null,
                $row['unknown_status_count'] ?? null,
            ];
        }

        $totals = $dataset['totals'];
        $rows[] = [];
        $rows[] = ['Total', $totals['total_transactions'] ?? null, $totals['success_purchases'] ?? null, null, null, $totals['revenue'] ?? null];

        return $rows;
    }

    /**
     * The 24 WIB hours of the chosen day of the week.
     *
     * @param  array<string, mixed>  $hourly
     * @return list<list<string|int|float|null>>
     */
    private function hourlyRows(array $hourly): array
    {
        $rows = [
            ['Zona Waktu', 'WIB (Asia/Jakarta)'],
            ['Pilihan Hari', $hourly['day_label']],
            ['Periode Aktif', $hourly['active']['date_from'].' s/d '.$hourly['active']['date_to'], $hourly['active']['days'].' hari'],
            $hourly['comparison'] === null
                ? ['Periode Pembanding', $hourly['comparison_message'] ?? 'Tidak ada pembanding']
                : ['Periode Pembanding', $hourly['comparison']['date_from'].' s/d '.$hourly['comparison']['date_to'], $hourly['comparison']['days'].' hari'],
            [],
            [
                'Jam', 'Transaksi Berhasil', 'Omzet', 'Ketersediaan', 'Batas Data',
                'Transaksi Berhasil Pembanding', 'Omzet Pembanding', 'Ketersediaan Pembanding', 'Batas Data Pembanding',
            ],
        ];

        foreach ($hourly['slots'] as $slot) {
            $comparison = $slot['comparison'];

            $rows[] = [
                $slot['label'],
                $slot['active']['success_payments'],
                $slot['active']['revenue'],
                $this->availability($slot['active']['availability']),
                $slot['active']['as_of'],
                $comparison['success_payments'] ?? null,
                $comparison['revenue'] ?? null,
                $comparison === null ? 'Tidak ada pembanding' : $this->availability($comparison['availability']),
                $comparison['as_of'] ?? null,
            ];
        }

        $rows[] = [];
        $rows[] = [
            'Total',
            $hourly['active']['totals']['success_payments'],
            $hourly['active']['totals']['revenue'],
            $this->availability($hourly['active']['availability']),
            $hourly['active']['as_of'],
            $hourly['comparison']['totals']['success_payments'] ?? null,
            $hourly['comparison']['totals']['revenue'] ?? null,
            $hourly['comparison'] === null ? null : $this->availability($hourly['comparison']['availability']),
            $hourly['comparison']['as_of'] ?? null,
        ];

        return $rows;
    }

    /**
     * Transaksi Berhasil, Omzet, both shares, and Konversi per product type. The share of each metric comes from the
     * dataset read with that metric.
     *
     * @return list<list<string|int|float|null>>
     */
    private function productTypeRows(ResolvedPeriod $period): array
    {
        $byPayments = $this->transactionCompositionService->productTypeDistribution($period, TransactionCompositionService::METRIC_SUCCESS_PAYMENTS);
        $byRevenue = $this->transactionCompositionService->productTypeDistribution($period, TransactionCompositionService::METRIC_REVENUE);
        $revenueShares = [];

        foreach ($byRevenue['product_types'] as $type) {
            $revenueShares[$type['name']] = [$type['active']['share_percentage'] ?? null, $type['comparison']['share_percentage'] ?? null];
        }

        $side = fn (?array $values, ?float $revenueShare) => [
            $values['success_payments'] ?? null,
            $values['share_percentage'] ?? null,
            $values['revenue'] ?? null,
            $revenueShare,
            $values['conversion']['value'] ?? null,
            $values['conversion']['provisional_label'] ?? null,
            $values['conversion']['unknown_status_count'] ?? null,
        ];

        $rows = [
            ['Dasar Tanggal', 'Transaksi Berhasil dan Omzet: tanggal bayar. Konversi: tanggal transaksi dibuat.'],
            ...$this->sideMetaRows($byPayments),
            [],
            [
                'Tipe Produk',
                'Transaksi Berhasil', 'Porsi Transaksi Berhasil (%)', 'Omzet', 'Porsi Omzet (%)', 'Konversi (%)', 'Label Konversi', 'Status Belum Tersedia',
                'Transaksi Berhasil Pembanding', 'Porsi Transaksi Berhasil Pembanding (%)', 'Omzet Pembanding', 'Porsi Omzet Pembanding (%)', 'Konversi Pembanding (%)', 'Label Konversi Pembanding', 'Status Belum Tersedia Pembanding',
            ],
        ];

        foreach ($byPayments['product_types'] as $type) {
            [$activeShare, $comparisonShare] = $revenueShares[$type['name']] ?? [null, null];

            $rows[] = [
                $type['name'],
                ...$side($type['active'], $activeShare),
                ...$side($type['comparison'], $comparisonShare),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $dataset  A dataset with active and comparison side metadata.
     * @return list<list<string|null>>
     */
    private function sideMetaRows(array $dataset): array
    {
        $meta = fn (string $label, ?array $side) => $side === null
            ? ["Periode {$label}", 'Tidak ada pembanding']
            : ["Periode {$label}", "{$side['date_from']} s/d {$side['date_to']}", $this->availability($side['availability']), $side['as_of'], $this->source($side['source'] ?? null)];

        return [$meta('Aktif', $dataset['active']), $meta('Pembanding', $dataset['comparison'])];
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function windowRows(string $label, PeriodWindow $window): array
    {
        return [
            ["Periode {$label}", "{$window->dateFrom->toDateString()} s/d {$window->dateTo->toDateString()}", $window->days().' hari'],
            ["Ketersediaan {$label}", $this->availability($window->availability->value)],
            ["Batas Data {$label}", $window->cutoffAt?->toIso8601String() ?? self::UNAVAILABLE],
            ["Keadaan Data {$label}", $window->stateBasis === PeriodStateBasis::AtCutoff
                ? 'Keadaan pada cutoff (catatan '.($window->cutoffAt?->toIso8601String() ?? self::UNAVAILABLE).')'
                : 'Keadaan terbaru'],
        ];
    }

    /**
     * The part of each period the change uses when some active days have no matching comparison day.
     *
     * @return list<list<string>>
     */
    private function changeBasisRows(ResolvedPeriod $period): array
    {
        $basis = $period->changeBasis?->toArray();
        $text = fn (array $ranges) => implode(', ', array_map(fn (array $range) => "{$range['date_from']} s/d {$range['date_to']}", $ranges));

        if ($basis === null) {
            return [];
        }

        return [
            ['Dasar Perubahan Dipotong', $basis['is_trimmed'] ? 'Ya' : 'Tidak'],
            ['Dasar Perubahan Aktif', $text($basis['active'])],
            ['Dasar Perubahan Pembanding', $text($basis['comparison'])],
        ];
    }

    private function availability(?string $availability): string
    {
        return self::AVAILABILITY_LABELS[$availability ?? DataAvailability::Unavailable->value] ?? (string) $availability;
    }

    private function source(?string $source): ?string
    {
        return match ($source) {
            'latest' => 'Keadaan terbaru',
            'snapshot' => 'Catatan pada cutoff',
            default => null,
        };
    }
}
