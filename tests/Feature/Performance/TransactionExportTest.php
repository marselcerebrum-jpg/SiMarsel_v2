<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\TransactionListExportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

/**
 * FIX-14-BE-02: Ekspor Daftar Transaksi in Excel and CSV, with the list filter, 13 columns, and every row.
 */
class TransactionExportTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_URL = '/api/performance/transactions';

    private const EXPORT_URL = '/api/performance/transactions/export';

    private const RANGE = 'date_from=2026-11-01&date_to=2026-11-30';

    private Account $manager;

    private Platform $platformA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
    }

    public function test_the_csv_has_the_13_headers_in_order_and_every_row_of_the_list_filter_in_the_list_order(): void
    {
        foreach (range(1, 100) as $i) {
            $this->trx('T-'.$i, ['markaz_created_at' => '2026-11-'.str_pad((string) (($i % 4) + 1), 2, '0', STR_PAD_LEFT).' 10:00:00']);
        }

        $query = self::RANGE.'&statuses[]=success&search=T-';
        $rows = $this->csv($this->download('csv', $query));

        $this->assertSame(TransactionListExportService::HEADERS, $rows[0]);
        $this->assertCount(13, $rows[0]);
        $this->assertCount(101, $rows, 'One header row and one row per checkout.');

        $listed = $this->listedTrxIds($query.'&per_page=100');
        $this->assertCount(100, $listed);
        $this->assertSame($listed, array_column(array_slice($rows, 1), 0), 'The file and the list read the same rows in the same order.');
    }

    public function test_the_csv_money_and_status_cells_match_the_detail_and_pending_has_no_revenue_contribution(): void
    {
        $money = ['product_price' => 200000, 'voucher_discount' => 20000, 'admin_fee' => 3000, 'nominal' => 183000, 'net_nominal' => 180000];
        $this->trx('SUCCESS', ['trx_id' => 'SUCCESS', ...$money]);
        $this->trx('PENDING', ['trx_id' => 'PENDING', ...$money, ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null, 'paid_time' => null]);

        $rows = collect($this->csv($this->download('csv', self::RANGE)))->keyBy(0);
        $header = TransactionListExportService::HEADERS;
        $success = array_combine($header, $rows['SUCCESS']);
        $pending = array_combine($header, $rows['PENDING']);

        $this->assertSame('200000', $success['Harga Paket']);
        $this->assertSame('20000', $success['Potongan Voucher']);
        $this->assertSame('3000', $success['Biaya Admin']);
        $this->assertSame('183000', $success['Nominal']);
        $this->assertSame('180000', $success['Kontribusi Omzet']);
        $this->assertSame('Success', $success['Status']);

        $this->assertSame('183000', $pending['Nominal']);
        $this->assertSame('', $pending['Kontribusi Omzet'], 'Pending keeps its billed nominal and an empty revenue cell.');
        $this->assertSame('Pending', $pending['Status']);
        $this->assertSame('Belum tersedia', $pending['Tanggal Dibayar']);
    }

    public function test_group_members_and_claims_follow_the_list_rules_and_text_is_not_a_formula(): void
    {
        $this->trx('GRP-1', ['markaz_transaction_id' => 5001, 'nominal' => 300000, 'net_nominal' => 300000]);
        $this->trx('GRP-1-M1', ['markaz_transaction_id' => 5002, 'markaz_ref_id' => 5001]);
        $this->trx('CLAIM-1', ['markaz_transaction_id' => 5004, 'is_claim_coupon' => true, 'nominal' => 0, 'net_nominal' => 0]);
        $this->trx('FORMULA', ['product' => '=HYPERLINK("http://example.test","x")', 'customer_name' => '+62 Rina']);

        $rows = $this->csv($this->download('csv', self::RANGE));
        $trxIds = array_column(array_slice($rows, 1), 0);

        $this->assertEqualsCanonicalizing(['GRP-1', 'CLAIM-1', 'FORMULA'], $trxIds);
        $this->assertNotContains('GRP-1-M1', $trxIds);

        $formula = collect($rows)->firstWhere(0, 'FORMULA');
        $this->assertSame("'=HYPERLINK(\"http://example.test\",\"x\")", $formula[4]);
        $this->assertSame("'+62 Rina", $formula[5]);

        $claim = collect($rows)->firstWhere(0, 'CLAIM-1');
        $this->assertSame('', $claim[11], 'A claim adds no revenue.');
    }

    public function test_the_xlsx_has_one_sheet_with_the_same_13_headers_and_rows(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi PHP zip tidak aktif: file XLSX tidak bisa dibuat di lingkungan ini.');
        }

        $this->trx('XLSX-1', ['product' => '=SUM(1,1)']);
        $this->trx('XLSX-2');

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $this->download('xlsx', self::RANGE));

        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = iterator_to_array($reader->getSheetIterator());
        $this->assertCount(1, $sheets);

        $rows = [];
        foreach ($sheets[0]->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }
        $reader->close();
        unlink($path);

        $this->assertSame(TransactionListExportService::HEADERS, array_slice($rows[0], 0, 13));
        $this->assertCount(3, $rows);
        $this->assertSame('=SUM(1,1)', $rows[1][4], 'In XLSX the text stays text and is not changed.');
    }

    public function test_be03_the_detail_and_the_csv_show_the_same_financial_details_and_report_statuses(): void
    {
        $money = ['product_price' => 200000, 'voucher_discount' => 20000, 'admin_fee' => 3000, 'nominal' => 183000, 'net_nominal' => 180000];
        $rows = [
            'SUCCESS' => $this->trx('SUCCESS', $money),
            'PENDING-ZERO' => $this->trx('PENDING-ZERO', [...$money, ...$this->statuses(MarkazTransactionStatus::Success, MarkazTransactionStatus::Pending), 'paid_date' => null]),
            'EXPIRED' => $this->trx('EXPIRED', [...$money, ...$this->statuses(MarkazTransactionStatus::Pending, MarkazTransactionStatus::Expired), 'paid_date' => null]),
            'FAILED' => $this->trx('FAILED', [...$money, ...$this->statuses(MarkazTransactionStatus::Failed)]),
            'CANCELED' => $this->trx('CANCELED', [...$money, ...$this->statuses(MarkazTransactionStatus::Canceled), 'paid_date' => null]),
            'UNKNOWN' => $this->trx('UNKNOWN', [...$money, 'source_status' => null, 'effective_status' => null, 'paid_date' => null]),
        ];

        $csv = collect($this->csv($this->download('csv', self::RANGE)))->keyBy(0);
        $header = TransactionListExportService::HEADERS;
        $expected = ['SUCCESS' => 'Success', 'PENDING-ZERO' => 'Pending', 'EXPIRED' => 'Expired', 'FAILED' => 'Failed', 'CANCELED' => 'Canceled', 'UNKNOWN' => 'Status belum tersedia'];

        foreach ($rows as $trxId => $transaction) {
            $detail = $this->actingAs($this->manager)->getJson(self::LIST_URL.'/'.$transaction->id)->assertOk()->json('data');
            $cells = array_combine($header, $csv[$trxId]);

            $this->assertSame($expected[$trxId], $detail['status']['label'], $trxId);
            $this->assertSame($detail['status']['label'], $cells['Status'], $trxId);

            foreach (['product_price' => 'Harga Paket', 'voucher_discount' => 'Potongan Voucher', 'admin_fee' => 'Biaya Admin', 'nominal' => 'Nominal'] as $field => $column) {
                $this->assertEquals($detail['financials'][$field], $cells[$column], "{$trxId} {$column}");
            }

            $contribution = $detail['financials']['revenue_contribution'];
            $this->assertSame($contribution === null ? '' : (string) (int) $contribution, $cells['Kontribusi Omzet'], $trxId);
        }

        $this->assertSame('180000', array_combine($header, $csv['SUCCESS'])['Kontribusi Omzet']);
        $this->assertSame('', array_combine($header, $csv['FAILED'])['Kontribusi Omzet'], 'A Failed payment leaves Omzet.');
    }

    public function test_an_unknown_format_is_rejected_and_a_guest_gets_no_file(): void
    {
        $this->actingAs($this->manager)->getJson(self::EXPORT_URL.'?'.self::RANGE.'&format=pdf')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        $this->actingAs($this->manager)->getJson(self::EXPORT_URL.'?'.self::RANGE)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        $this->app['auth']->forgetGuards();
        $this->getJson(self::EXPORT_URL.'?'.self::RANGE.'&format=csv')->assertUnauthorized();
    }

    /**
     * @return array<int, string>
     */
    private function listedTrxIds(string $query): array
    {
        return array_column($this->actingAs($this->manager)->getJson(self::LIST_URL.'?'.$query)->json('data'), 'trx_id');
    }

    private function download(string $format, string $query): string
    {
        $response = $this->actingAs($this->manager)
            ->get(self::EXPORT_URL.'?'.$query.'&format='.$format)
            ->assertOk()
            ->assertHeader('content-disposition');

        return $response->streamedContent();
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        return array_map('str_getcsv', array_values(array_filter(preg_split('/\R/', $content), fn (string $line) => $line !== '')));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trx(string $trxId, array $overrides = []): Transaction
    {
        return Transaction::factory()->forPlatform($this->platformA)->create([
            'trx_id' => $trxId,
            'markaz_created_at' => '2026-11-12 10:00:00',
            'paid_date' => '2026-11-12',
            'paid_time' => '10:05:00',
            'net_nominal' => 100000,
            ...$overrides,
        ]);
    }

    /**
     * @return array{source_status: int, effective_status: int|null}
     */
    private function statuses(MarkazTransactionStatus $source, ?MarkazTransactionStatus $effective = null): array
    {
        return ['source_status' => $source->value, 'effective_status' => $effective?->value];
    }
}
