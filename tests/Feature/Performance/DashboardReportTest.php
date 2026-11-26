<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Enums\TrendPeriod;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\DashboardReportService;
use App\Services\PaymentMethodComparisonService;
use App\Services\PerformanceSummaryService;
use App\Services\PeriodResolverService;
use App\Services\ProductPerformanceService;
use App\Services\TransactionCompositionService;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodSelection;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

/**
 * FIX-14-BE-01 and BE-03: the nine-sheet Laporan Dashboard, checked cell by cell against the component datasets of the
 * screen for the same filter, cutoff, granularity, and day choice.
 */
class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/export/dashboard';

    /** Wednesday 11 Nov 2026: last 7 days = Wed 04 - Tue 10 Nov, comparison Wed 28 Okt - Tue 03 Nov. */
    private const NOW = '2026-11-11T14:30:00+07:00';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    public function test_the_workbook_has_the_nine_sheets_in_order_with_the_report_metadata(): void
    {
        $this->lastSevenDaysCoverage();

        $sheets = $this->sheets(PeriodPreset::Last7Days, TrendPeriod::Weekly, 5);

        $this->assertSame(DashboardReportService::SHEETS, array_keys($sheets));
        $this->assertCount(9, $sheets);

        $meta = $this->keyed($sheets['Ringkasan Sales']);
        $this->assertSame('2026-11-04 s/d 2026-11-10', $meta['Periode Aktif'][1]);
        $this->assertSame('2026-10-28 s/d 2026-11-03', $meta['Periode Pembanding'][1]);
        $this->assertSame('Keadaan terbaru', $meta['Keadaan Data Aktif'][1]);
        $this->assertSame('Keadaan terbaru', $meta['Keadaan Data Pembanding'][1]);
        $this->assertSame('Mingguan', $meta['Pengelompokan Tren'][1]);
        $this->assertSame('Jumat', $meta['Pilihan Hari Jam Ramai'][1]);
        $this->assertStringContainsString('tanggal bayar', $meta['Dasar Tanggal'][1]);
    }

    public function test_ac04_sales_summary_cells_match_the_component_with_net_nominal_revenue_and_a_provisional_conversion(): void
    {
        $this->lastSevenDaysCoverage();
        // BE-03 AC-03: 182.000 and 185.000 with admin fees 2.000 and 5.000, both net 180.000.
        $this->trx('2026-11-08 09:00:00', '2026-11-08 09:05:00', 180000, nominal: 182000);
        $this->trx('2026-11-08 10:00:00', '2026-11-08 10:05:00', 180000, nominal: 185000);
        // BE-03 AC-04/AC-11: a Pending purchase, a Success claim, and a purchase without a status.
        $this->trx('2026-11-08 11:00:00', null, 90000, MarkazTransactionStatus::Pending);
        $this->trx('2026-11-08 12:00:00', '2026-11-08 12:00:00', 0, claim: true);
        $this->trx('2026-11-08 13:00:00', null, 70000, null);

        $sheet = $this->sheets(PeriodPreset::Last7Days)['Ringkasan Sales'];
        $header = $this->headerOf($sheet, 'Metrik');
        $metrics = $this->table($sheet, 'Metrik');
        $component = collect($this->salesComponent()['sales_summary']['metrics'])->keyBy('label');

        $this->assertCount(7, $metrics);

        foreach ($metrics as $label => $row) {
            $this->assertSame($component[$label]['active']['value'], $row[$header['Nilai Aktif']], $label);
            $this->assertSame($component[$label]['comparison']['value'] ?? null, $row[$header['Nilai Pembanding']], $label);
            $this->assertSame($component[$label]['change']['value'] ?? null, $row[$header['Perubahan']], $label);
        }

        $this->assertEquals(360000, $metrics['Omzet'][$header['Nilai Aktif']], 'Omzet is net_nominal, not nominal 367.000.');
        $this->assertEquals(180000, $metrics['Rata-rata Nilai Order'][$header['Nilai Aktif']]);
        $this->assertSame(5, $metrics['Total Transaksi'][$header['Nilai Aktif']]);
        // 2 Success of 4 purchase attempts; the claim is left out of both sides.
        $this->assertEquals(50, $metrics['Konversi Transaksi'][$header['Nilai Aktif']]);
        $this->assertSame('Sementara', $metrics['Konversi Transaksi'][$header['Label Konversi']]);
        $this->assertSame(1, $metrics['Konversi Transaksi'][$header['Status Belum Tersedia']]);
        $this->assertSame('Poin persen', $metrics['Konversi Transaksi'][$header['Unit Perubahan']]);
    }

    public function test_ac03_the_trend_follows_the_granularity_and_writes_every_series_on_its_own_date_basis(): void
    {
        $this->lastSevenDaysCoverage();
        // BE-03 AC-02: created on the 5th, paid on the 6th.
        $this->trx('2026-11-05 22:00:00', '2026-11-06 08:00:00', 200000);

        $daily = $this->sheets(PeriodPreset::Last7Days, TrendPeriod::Daily)['Tren dan Performa'];
        $header = $this->headerOf($daily, 'Bucket Aktif');
        $buckets = $this->table($daily, 'Bucket Aktif');

        $this->assertCount(7, array_filter(array_keys($buckets), fn ($key) => $key !== 'Total'));
        $this->assertSame(1, $buckets['05 Nov 2026'][$header['Total Transaksi']]);
        $this->assertEquals(0, $buckets['05 Nov 2026'][$header['Omzet']]);
        $this->assertSame(0, $buckets['06 Nov 2026'][$header['Total Transaksi']]);
        $this->assertEquals(200000, $buckets['06 Nov 2026'][$header['Omzet']]);
        // The comparison series is written even when the screen hides it.
        $this->assertSame('29 Okt 2026', $buckets['05 Nov 2026'][$header['Bucket Pembanding']]);
        $this->assertSame(0, $buckets['05 Nov 2026'][$header['Total Transaksi Pembanding']]);

        $weekly = $this->table($this->sheets(PeriodPreset::Last7Days, TrendPeriod::Weekly)['Tren dan Performa'], 'Bucket Aktif');
        $this->assertCount(2, array_filter(array_keys($weekly), fn ($key) => $key !== 'Total'));
    }

    public function test_ac04_status_payment_method_and_product_sheets_write_claims_and_the_status_unavailable_count(): void
    {
        $this->lastSevenDaysCoverage();
        $this->trx('2026-11-08 09:00:00', '2026-11-08 09:05:00', 180000, method: 'QRIS', product: 'Paket A');
        $this->trx('2026-11-08 10:00:00', '2026-11-08 10:00:00', 0, claim: true, method: 'QRIS', product: 'Paket A');
        $this->trx('2026-11-08 11:00:00', null, 70000, null, method: 'QRIS', product: 'Paket A');
        $buyer = $this->trx('2026-11-08 12:00:00', '2026-11-08 12:05:00', 300000, method: 'QRIS', product: 'Paket A');
        $this->trx('2026-11-08 12:00:00', '2026-11-08 12:05:00', 0, refId: $buyer->markaz_transaction_id, method: 'QRIS', product: 'Paket A');

        $sheets = $this->sheets(PeriodPreset::Last7Days);
        $period = $this->filter(PeriodPreset::Last7Days)->period;

        $statuses = $this->table($sheets['Status Transaksi'], 'Status');
        $statusHeader = $this->headerOf($sheets['Status Transaksi'], 'Status');
        $component = app(TransactionCompositionService::class)->statusDistribution($period);
        $this->assertSame(3, $statuses['Success'][$statusHeader['Jumlah']], 'Two purchases and one claim; the group member is left out.');
        $this->assertSame(2, $statuses['Success'][$statusHeader['Pembelian']]);
        $this->assertSame(1, $statuses['Success'][$statusHeader['Klaim']]);
        $this->assertSame($component['unknown_status']['active']['count'], $statuses['Status belum tersedia'][$statusHeader['Jumlah']]);
        $this->assertSame(1, $statuses['Status belum tersedia'][$statusHeader['Jumlah']]);
        $this->assertArrayHasKey('Subtotal Tidak Berhasil (Expired + Canceled + Failed)', $statuses);

        $methods = $this->table($sheets['Metode Pembayaran'], 'Metode Pembayaran');
        $methodHeader = $this->headerOf($sheets['Metode Pembayaran'], 'Metode Pembayaran');
        $methodComponent = app(PaymentMethodComparisonService::class)->compare($period)['rows'][0]['active'];
        $this->assertSame($methodComponent['claim_checkouts'], $methods['QRIS'][$methodHeader['Klaim Kupon']]);
        $this->assertSame(1, $methods['QRIS'][$methodHeader['Klaim Kupon']]);
        $this->assertSame(1, $methods['QRIS'][$methodHeader['Status Belum Tersedia']]);
        $this->assertEquals(480000, $methods['QRIS'][$methodHeader['Omzet']]);

        $productHeader = $this->headerOf($sheets['Performa Produk'], 'Produk');
        $products = $this->table($sheets['Performa Produk'], 'Produk');
        $productComponent = app(ProductPerformanceService::class)->all($period)['rows'][0];
        $this->assertSame(
            ['Produk', 'Total Transaksi', 'Transaksi Berhasil', 'Pending', 'Tidak Berhasil', 'Omzet', 'Kontribusi Transaksi (%)', 'Kontribusi Omzet (%)'],
            array_slice(array_keys($productHeader), 0, 8),
        );
        $this->assertSame($productComponent['total_transactions'], $products['Paket A'][$productHeader['Total Transaksi']]);
        $this->assertSame(1, $products['Paket A'][$productHeader['Klaim Kupon']]);
        $this->assertSame(1, $products['Paket A'][$productHeader['Status Belum Tersedia']]);
        $this->assertEquals(480000, $products['Paket A'][$productHeader['Omzet']]);
    }

    public function test_ac03_the_day_of_week_filters_jam_ramai_on_both_sides(): void
    {
        $this->lastSevenDaysCoverage();
        $this->trx('2026-11-06 08:00:00', '2026-11-06 19:20:00', 250000); // Friday
        $this->trx('2026-11-07 08:00:00', '2026-11-07 19:20:00', 100000); // Saturday

        $sheet = $this->sheets(PeriodPreset::Last7Days, dayOfWeek: 5)['Jam Ramai Pembelian'];
        $header = $this->headerOf($sheet, 'Jam');
        $hours = $this->table($sheet, 'Jam');

        $this->assertSame('Jumat', $this->keyed($sheet)['Pilihan Hari'][1]);
        $this->assertCount(24, array_filter(array_keys($hours), fn ($key) => $key !== 'Total'));
        $this->assertSame(1, $hours['19.00–19.59'][$header['Transaksi Berhasil']]);
        $this->assertEquals(250000, $hours['19.00–19.59'][$header['Omzet']]);
        $this->assertSame(0, $hours['19.00–19.59'][$header['Transaksi Berhasil Pembanding']]);
    }

    public function test_ac05_today_states_the_cutoff_and_the_recorded_comparison_while_yesterday_reads_the_latest_state(): void
    {
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 14:00:00');

        $today = $this->keyed($this->sheets(PeriodPreset::Today)['Ringkasan Sales']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $today['Batas Data Aktif'][1]);
        $this->assertStringStartsWith('Keadaan pada cutoff (catatan 2026-11-10T14:00:00+07:00', $today['Keadaan Data Pembanding'][1]);
        $this->assertSame('Per Jam', $today['Pengelompokan Tren'][1]);

        $this->coverage('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-11-09', '2026-11-09', '2026-11-10 00:00:00');
        $yesterday = $this->keyed($this->sheets(PeriodPreset::Yesterday)['Ringkasan Sales']);
        $this->assertSame('Keadaan terbaru', $yesterday['Keadaan Data Aktif'][1]);
        $this->assertSame('Keadaan terbaru', $yesterday['Keadaan Data Pembanding'][1]);
    }

    public function test_ac02_this_month_on_31_march_states_the_full_total_and_the_trimmed_change_basis(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31T10:00:00+07:00'));
        $this->coverage('2026-03-01', '2026-03-31', '2026-03-31 10:00:00');
        $this->coverage('2026-02-01', '2026-02-28', '2026-03-01 00:00:00');

        $meta = $this->keyed($this->sheets(PeriodPreset::ThisMonth)['Ringkasan Sales']);

        $this->assertSame('2026-03-01 s/d 2026-03-31', $meta['Periode Aktif'][1]);
        $this->assertSame('Ya', $meta['Dasar Perubahan Dipotong'][1]);
        $this->assertSame('2026-03-01 s/d 2026-03-28', $meta['Dasar Perubahan Aktif'][1]);
        $this->assertSame('2026-02-01 s/d 2026-02-28', $meta['Dasar Perubahan Pembanding'][1]);
    }

    public function test_the_endpoint_validates_the_filter_and_requires_a_session(): void
    {
        $this->actingAs($this->manager)->getJson(self::URL)->assertUnprocessable()->assertJsonValidationErrors('preset');
        $this->actingAs($this->manager)->getJson(self::URL.'?preset=today&day_of_week=8')->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
        $this->actingAs($this->manager)->getJson(self::URL.'?preset=last_90_days&granularity=hourly')->assertUnprocessable()->assertJsonValidationErrors('granularity');

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL.'?preset=today')->assertUnauthorized();
    }

    public function test_the_downloaded_excel_has_the_nine_sheets_in_order(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi PHP zip tidak aktif: file XLSX tidak bisa dibuat di lingkungan ini.');
        }

        $this->lastSevenDaysCoverage();

        $response = $this->actingAs($this->manager)->get(self::URL.'?preset=last_7_days&platforms[]=app-a')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dashboard-report-test-');
        file_put_contents($path, $response->streamedContent());

        $reader = new XlsxReader;
        $reader->open($path);
        $names = array_map(fn ($sheet) => $sheet->getName(), iterator_to_array($reader->getSheetIterator(), false));
        $reader->close();
        unlink($path);

        $this->assertSame(DashboardReportService::SHEETS, $names);
    }

    /**
     * @return array<string, list<list<string|int|float|null>>>
     */
    private function sheets(PeriodPreset $preset, ?TrendPeriod $granularity = null, ?int $dayOfWeek = null): array
    {
        $filter = $this->filter($preset);

        return app(DashboardReportService::class)->sheets(
            $filter,
            $this->filter($preset, PeriodSelection::DATASET_DOWNLOADERS),
            $granularity ?? TrendPeriod::defaultFor($filter->period),
            $dayOfWeek,
        );
    }

    private function filter(PeriodPreset $preset, string $dataset = PeriodSelection::DATASET_TRANSACTIONS): PerformanceFilter
    {
        return PerformanceFilter::fromResolvedPeriod(app(PeriodResolverService::class)->resolve(
            new PeriodSelection($preset, PeriodComparison::Auto, ['app-a'], $dataset, null, null, null, null),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function salesComponent(): array
    {
        return app(PerformanceSummaryService::class)->summarize(
            $this->filter(PeriodPreset::Last7Days),
            $this->filter(PeriodPreset::Last7Days, PeriodSelection::DATASET_DOWNLOADERS),
        );
    }

    /**
     * Rows keyed by their first cell.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, list<mixed>>
     */
    private function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            if (isset($row[0]) && is_string($row[0])) {
                $keyed[$row[0]] ??= $row;
            }
        }

        return $keyed;
    }

    /**
     * Column name => index of the table whose header starts with $first.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, int>
     */
    private function headerOf(array $rows, string $first): array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $first) {
                return array_flip($row);
            }
        }

        $this->fail("Header {$first} not found.");
    }

    /**
     * The rows below the header starting with $first, keyed by their first cell.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, list<mixed>>
     */
    private function table(array $rows, string $first): array
    {
        $start = null;

        foreach ($rows as $index => $row) {
            if (($row[0] ?? null) === $first) {
                $start = $index;
                break;
            }
        }

        $table = [];

        foreach (array_slice($rows, $start + 1) as $row) {
            if ($row !== [] && isset($row[0])) {
                $table[(string) $row[0]] = $row;
            }
        }

        return $table;
    }

    private function trx(
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?int $refId = null,
        ?float $nominal = null,
        string $method = 'BCA',
        string $product = 'Paket A',
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($this->platform)->create([
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $nominal ?? $netNominal,
            'net_nominal' => $netNominal,
            'payment_method' => $method,
            'product' => $product,
        ]);
    }

    private function lastSevenDaysCoverage(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
    }

    private function coverage(string $from, string $to, string $cutoffAt): void
    {
        DashboardSnapshot::factory()->for($this->platform)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => [],
            'recorded_at' => $cutoffAt,
        ]);
    }
}
