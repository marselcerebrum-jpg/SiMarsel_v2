<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Services\PromoAnalysisExportService;
use App\Services\PromoAnalysisService;
use App\Services\PromoCodeService;
use App\Services\PromoCodeSummaryService;
use App\Services\PromoProductTypeService;
use App\Services\PromoTrendService;
use App\Support\SpreadsheetWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class PromoAnalysisExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/export';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
        app(PromoCodeService::class)->register($this->jadiasn->id, 'APP26', Funnel::where('name', 'Aplikasi')->value('id'), PromoSource::where('name', 'Instagram')->value('id'));
        app(PromoCodeService::class)->register($this->jadiasn->id, 'RESELLER01', Funnel::where('name', 'Sales')->value('id'), PromoSource::where('name', 'Reseller')->value('id'));
        $this->travelTo(CarbonImmutable::create(2026, 10, 15, 8));
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(?array $codes, float $nominal, string $paidDate, string $productType = Transaction::TYPE_FIRST, ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'paid_time' => '10:00:00',
            'promo_code' => $codes,
            'nominal' => $nominal,
            'product_type' => $productType,
        ]);
    }

    /**
     * September 2026: 5 transactions (1.500.000), 5 code usages.
     */
    private function seedExportData(): void
    {
        $this->transaction(['APP26'], 100000, '2026-09-01');
        $this->transaction(['APP26', 'RESELLER01'], 200000, '2026-09-08', Transaction::TYPE_RENEWAL);
        $this->transaction(['', 'RESELLER01'], 300000, '2026-09-10');
        $this->transaction([' unreg26 '], 400000, '2026-09-15', 'Bundling', $this->cerebrum);
        $this->transaction(null, 500000, '2026-09-20');
    }

    private function export(string $query = self::SEPTEMBER): TestResponse
    {
        return $this->actingAs($this->manager)->get(self::URL.$query);
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(TestResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];

            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        return $sheets;
    }

    /**
     * The row of a label sheet whose first cell is the label.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<mixed>
     */
    private function row(array $rows, string $label): array
    {
        return collect($rows)->first(fn (array $row) => ($row[0] ?? null) === $label);
    }

    public function test_guest_cannot_export(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_export_has_every_worksheet_with_the_numbers_of_the_page(): void
    {
        $this->seedExportData();

        $response = $this->export(self::SEPTEMBER.'&period=weekly')->assertOk();
        $this->assertSame('attachment; filename=promo-analisis_2026-09-01_2026-09-30.xlsx', $response->headers->get('content-disposition'));

        $sheets = $this->sheets($response);
        $this->assertSame(['Ringkasan', 'Funnel', 'Source', 'Rekap Kode Promo', 'Detail Penggunaan', 'Tren', 'Perdana vs Perpanjangan'], array_keys($sheets));

        $this->actingAs($this->manager);
        $page = fn (string $path, string $query = self::SEPTEMBER) => $this->getJson('/api/promo-analysis/'.$path.$query)->json();

        // Ringkasan: the active filter, then the six KPI cards with their comparison.
        $summary = $sheets['Ringkasan'];
        $this->assertSame(['Periode', '2026-09-01 s/d 2026-09-30'], $this->row($summary, 'Periode'));
        $this->assertSame(['Periode Pembanding', '2026-08-01 s/d 2026-08-31'], $this->row($summary, 'Periode Pembanding'));
        $this->assertSame(['Platform', 'Semua platform'], $this->row($summary, 'Platform'));
        $this->assertSame(['Metode Pembayaran', 'Semua metode pembayaran'], $this->row($summary, 'Metode Pembayaran'));
        $kpis = $page('summary')['data']['kpis'];
        $this->assertEquals(['Total Transaksi dengan Promo', $kpis['promo_transactions']['value'], 0, null, 'Naik'], array_pad($this->row($summary, 'Total Transaksi dengan Promo'), 5, null));
        $this->assertEquals(['Revenue tanpa Promo', $kpis['non_promo_revenue']['value'], 0, null, 'Naik'], array_pad($this->row($summary, 'Revenue tanpa Promo'), 5, null));
        $this->assertEquals(['Kode Promo Digunakan', 3, 0, null, 'Naik'], array_pad($this->row($summary, 'Kode Promo Digunakan'), 5, null));

        // Funnel, Source, and Perdana vs Perpanjangan: every row of the page, in the same order.
        $this->assertEquals(
            array_map(fn (array $row) => [$row['name'], $row['transactions'], $row['revenue'], $row['contribution_percentage']], $page('funnels')['data']['funnels']),
            array_slice($sheets['Funnel'], 1),
        );
        $this->assertEquals(
            array_map(fn (array $row) => [$row['name'], $row['usages'], $row['revenue'], $row['contribution_percentage']], $page('sources')['data']['sources']),
            array_slice($sheets['Source'], 1),
        );
        $this->assertEquals(
            array_map(fn (array $row) => [$row['name'], $row['transactions'], $row['revenue'], $row['transaction_percentage'], $row['revenue_percentage']], $page('product-types')['data']['product_types']),
            array_slice($sheets['Perdana vs Perpanjangan'], 1),
        );

        // Tren follows the selected period.
        $this->assertEquals(['Periode', 'Tanggal Mulai', 'Tanggal Akhir', 'Transaksi dengan Promo', 'Revenue dengan Promo', 'Total Transaksi', 'Total Penjualan'], $sheets['Tren'][0]);
        $this->assertEquals(['07 - 13 Sep 2026', '2026-09-07', '2026-09-13', 2, 500000, 2, 500000], $sheets['Tren'][2]);
        $this->assertCount(6, $sheets['Tren']);
    }

    public function test_rekap_and_detail_include_every_row_and_unregistered_codes(): void
    {
        $this->seedExportData();
        // More codes than one page of the table.
        foreach (range(1, 20) as $number) {
            $this->transaction(['EXTRA'.$number], 1000, '2026-09-25');
        }

        $sheets = $this->sheets($this->export(self::SEPTEMBER.'&sort=code&direction=asc')->assertOk());

        $this->actingAs($this->manager);
        $rekap = $this->getJson('/api/promo-analysis/codes'.self::SEPTEMBER.'&sort=code&direction=asc&per_page=100')->json('data');
        $this->assertCount(23, $rekap);
        $this->assertEquals(
            array_map(fn (array $row) => [
                $row['code'], implode(', ', $row['raw_codes']), $row['is_registered'] ? 'Terdaftar' : 'Belum terdaftar', $row['platform']['name'],
                $row['funnel']['name'] ?? null, $row['source']['name'] ?? null, $row['usages'], $row['revenue'], $row['contribution_percentage'],
            ], $rekap),
            array_slice($sheets['Rekap Kode Promo'], 1),
        );
        $unregistered = collect($sheets['Rekap Kode Promo'])->firstWhere(0, 'UNREG26');
        $this->assertSame([' unreg26 ', 'Belum terdaftar', 'CEREBRUM'], [$unregistered[1], $unregistered[2], $unregistered[3]]);

        // Detail: one row per code usage of the filter, newest first, with the same attribution as the code detail.
        $detail = $sheets['Detail Penggunaan'];
        $this->assertSame(['Tanggal', 'Jam', 'ID Transaksi', 'Platform', 'Kode Promo', 'Kode Asli Markaz', 'Posisi Kode', 'Status', 'Funnel', 'Source', 'Jumlah Penggunaan', 'Revenue', 'Nominal Transaksi'], $detail[0]);
        $this->assertCount(1 + 25, $detail);
        $twoCodes = array_values(array_filter($detail, fn (array $row) => $row[0] === '2026-09-08'));
        $this->assertEquals([
            ['APP26', 'APP26', 1, 'Terdaftar', 'Aplikasi', 'Instagram', 1, 0, 200000],
            ['RESELLER01', 'RESELLER01', 2, 'Terdaftar', 'Sales', 'Reseller', 1, 200000, 200000],
        ], array_map(fn (array $row) => array_slice($row, 4), $twoCodes));
        $this->assertEquals(
            collect($rekap)->sum('revenue'),
            array_sum(array_column(array_slice($detail, 1), 11)),
        );
    }

    public function test_export_follows_the_active_filter(): void
    {
        $this->seedExportData();

        $sheets = $this->sheets($this->export(self::SEPTEMBER.'&platforms[]=cerebrum&search=unreg')->assertOk());

        $this->assertSame(['Platform', 'CEREBRUM'], $this->row($sheets['Ringkasan'], 'Platform'));
        $this->assertSame(['Pencarian Kode Promo', 'unreg'], $this->row($sheets['Ringkasan'], 'Pencarian Kode Promo'));
        $this->assertSame(['UNREG26'], array_column(array_slice($sheets['Rekap Kode Promo'], 1), 0));
        $this->assertCount(2, $sheets['Detail Penggunaan']);
        $this->assertEquals(
            [['Perdana', 0], ['Perpanjangan', 0], ['Bundling', 1]],
            array_map(fn (array $row) => [$row[0], $row[1]], array_slice($sheets['Perdana vs Perpanjangan'], 1)),
        );
    }

    public function test_rekap_and_detail_sheets_keep_the_saved_labels_after_a_rename(): void
    {
        $this->seedExportData();
        Funnel::where('name', 'Aplikasi')->first()->update(['name' => 'Aplikasi Mobile']);

        $sheets = $this->sheets($this->export()->assertOk());

        $this->assertSame('Aplikasi', collect($sheets['Rekap Kode Promo'])->firstWhere(0, 'APP26')[4]);
        $this->assertSame(['Aplikasi'], collect($sheets['Detail Penggunaan'])->where(4, 'APP26')->pluck(8)->unique()->values()->all());
    }

    public function test_anomalous_transactions_are_left_out(): void
    {
        $this->seedExportData();
        $this->transaction(['APP26'], 70000, '2026-09-25')->update(['promo_code' => ['APP26', 'RESELLER01', 'EXTRA']]);

        $sheets = $this->sheets($this->export()->assertOk());

        $this->assertCount(1 + 5, $sheets['Detail Penggunaan']);
        $this->assertSame(2, collect($sheets['Rekap Kode Promo'])->firstWhere(0, 'APP26')[6]);
    }

    public function test_empty_filter_still_exports_every_sheet(): void
    {
        $sheets = $this->sheets($this->export('?year=2026&month=11')->assertOk());

        $this->assertCount(7, $sheets);
        $this->assertCount(1, $sheets['Rekap Kode Promo']);
        $this->assertCount(1, $sheets['Detail Penggunaan']);
        $this->assertSame(['Perdana', 0, 0, 0, 0], $sheets['Perdana vs Perpanjangan'][1]);
    }

    public function test_export_does_not_change_master_data(): void
    {
        $this->seedExportData();
        $before = [PromoCode::count(), Funnel::count(), PromoSource::count(), Transaction::count()];

        $this->export()->assertOk();

        $this->assertSame($before, [PromoCode::count(), Funnel::count(), PromoSource::count(), Transaction::count()]);
    }

    public function test_invalid_export_requests_get_readable_messages(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
        $this->getJson(self::URL.'?period=yearly')->assertUnprocessable()->assertJsonValidationErrors(['period' => 'Periode tidak valid.']);
        $this->getJson(self::URL.'?sort=type')->assertUnprocessable()->assertJsonValidationErrors(['sort' => 'Urutan tidak valid.']);
        $this->getJson(self::URL.'?date_from=2025-01-01&date_to=2026-06-30')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to' => 'Rentang tanggal untuk periode daily maksimal 366 hari.']);
    }

    public function test_an_export_too_large_for_excel_is_refused_with_a_readable_message(): void
    {
        $this->seedExportData();
        // A tiny worksheet limit stands in for Excel's 1.048.576 rows.
        $dependencies = array_map(fn (string $class) => app($class), [
            PromoAnalysisService::class,
            PromoCodeSummaryService::class,
            PromoTrendService::class,
            PromoProductTypeService::class,
            SpreadsheetWriter::class,
        ]);
        $this->instance(PromoAnalysisExportService::class, new class(...$dependencies) extends PromoAnalysisExportService
        {
            public const MAX_SHEET_ROWS = 4;
        });

        $this->actingAs($this->manager)->getJson(self::URL.self::SEPTEMBER)
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Data terlalu besar untuk diekspor: sheet Detail Penggunaan berisi 5 baris, maksimal 3 baris. Persempit periode atau filter, lalu coba lagi.']);
    }
}
