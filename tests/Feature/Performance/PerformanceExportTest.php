<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class PerformanceExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/export';

    private Account $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 16, 13, 45));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);

        foreach ([
            [$platformA, '2026-09-05', '08:15:00', 'BNI', 300000],
            [$platformA, '2026-09-20', '19:30:00', 'QRIS', 100000],
            [$platformB, '2026-09-21', '13:00:00', 'BNI', 100000],
        ] as [$platform, $date, $time, $method, $nominal]) {
            Transaction::factory()->for($platform)->create([
                'paid_date' => $date,
                'paid_time' => $time,
                'payment_method' => $method,
                'nominal' => $nominal,
                'product_type' => Transaction::TYPE_FIRST,
                'status_message' => Transaction::STATUS_SUCCESS,
            ]);
        }

        Downloader::factory()->for($platformA)->create(['date' => '2026-09-01', 'total' => 40]);
    }

    private function exportedFile(TestResponse $response): string
    {
        return $response->baseResponse->getFile()->getPathname();
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        return $sheets;
    }

    public function test_guest_cannot_export(): void
    {
        $this->get(self::URL.'?format=xlsx', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_format_and_filters_are_validated(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format' => 'Format wajib diisi.']);

        $this->getJson(self::URL.'?format=pdf')
            ->assertJsonValidationErrors(['format' => 'Format harus xlsx atau csv.']);

        $this->getJson(self::URL.'?format=xlsx&year=2026&month=9&years[]=2026')
            ->assertJsonValidationErrors(['filter']);
    }

    public function test_excel_export_has_one_sheet_per_widget_with_the_dashboard_numbers(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(self::URL.'?format=xlsx&year=2026&month=9')
            ->assertOk()
            ->assertDownload('performa_2026-09-01_2026-09-30.xlsx');

        $path = $this->exportedFile($response);
        $sheets = $this->readXlsx($path);
        unlink($path);

        $this->assertSame(['Ringkasan KPI', 'Tren', 'Metode Pembayaran', 'Kontribusi Platform', 'Waktu Pembelian'], array_keys($sheets));

        $kpis = $this->getJson('/api/performance/summary?year=2026&month=9')->json('data.kpis');
        $summary = $sheets['Ringkasan KPI'];
        $this->assertSame(['Periode', '2026-09-01 s/d 2026-09-30'], $summary[0]);
        $this->assertSame(['Platform', 'Semua platform'], $summary[2]);
        // The reader skips the blank row above the KPI table; empty cells are read as ''.
        $this->assertSame(['KPI', 'Nilai', 'Nilai Periode Sebelumnya', 'Perubahan (%)', 'Tren'], $summary[3]);
        $this->assertEquals(['Total Revenue', $kpis['total_revenue']['value'], 0, '', 'Naik'], $summary[4]);
        $this->assertEquals(['Konversi (%)', $kpis['conversion_rate']['value']], array_slice($summary[11], 0, 2));

        $trend = $sheets['Tren'];
        $this->assertSame(['Periode', 'Tanggal Mulai', 'Tanggal Akhir', 'Revenue', 'Transactions', 'Downloader', 'Conversion (%)', 'User Baru', 'Repeat Order'], $trend[0]);
        $this->assertCount(31, $trend);
        $this->assertEquals(['05 Sep 2026', '2026-09-05', '2026-09-05', 300000, 1, 0, '', 1, 0], $trend[5]);

        $this->assertEquals([
            ['Metode Pembayaran', 'Jumlah Transaksi', 'Revenue', 'Persentase Transaksi (%)', 'Persentase Kontribusi (%)'],
            ['BNI', 2, 400000, 66.67, 80],
            ['QRIS', 1, 100000, 33.33, 20],
        ], $sheets['Metode Pembayaran']);

        $this->assertEquals([
            ['Peringkat', 'Platform', 'Revenue', 'Transaksi', 'User', 'Persentase Kontribusi (%)'],
            [1, 'App A', 400000, 2, 2, 80],
            [2, 'App B', 100000, 1, 1, 20],
        ], $sheets['Kontribusi Platform']);

        $this->assertEquals(['Pagi', '06:00', '10:59', 1, 33.3], $sheets['Waktu Pembelian'][2]);
    }

    public function test_csv_export_lists_every_widget_one_after_another(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(self::URL.'?format=csv&year=2026&month=9&platforms[]=app-b')
            ->assertOk()
            ->assertDownload('performa_2026-09-01_2026-09-30.csv');

        $path = $this->exportedFile($response);
        $csv = file_get_contents($path);
        unlink($path);

        // Text with spaces is quoted, as usual in CSV.
        $this->assertStringStartsWith("\u{FEFF}\"Ringkasan KPI\"\n", $csv);
        $this->assertStringContainsString("Platform,app-b\n", $csv);
        $this->assertStringContainsString("\"Total Revenue\",100000,0,,Naik\n", $csv);
        $this->assertStringContainsString("\"Metode Pembayaran\"\n", $csv);
        $this->assertStringContainsString("BNI,1,100000,100,100\n", $csv);
        $this->assertStringContainsString("1,\"App B\",100000,1,1,100\n", $csv);
        $this->assertStringContainsString("\"Waktu Pembelian\"\n", $csv);
        $this->assertStringContainsString("Siang,11:00,14:59,1,100\n", $csv);
    }

    public function test_empty_period_still_exports_every_section(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(self::URL.'?format=xlsx&year=2026&month=1')
            ->assertOk();

        $path = $this->exportedFile($response);
        $sheets = $this->readXlsx($path);
        unlink($path);

        $this->assertCount(5, $sheets);
        $this->assertCount(1, $sheets['Metode Pembayaran']);
        $this->assertSame([1, 'App A', 0, 0, 0, 'N/A'], $sheets['Kontribusi Platform'][1]);
    }
}
