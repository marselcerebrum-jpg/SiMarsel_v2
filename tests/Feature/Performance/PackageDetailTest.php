<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageDetailTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/packages';

    private const EXPORT_URL = '/api/performance/packages/export';

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
    }

    private function transaction(
        Platform $platform,
        string $date,
        ?string $product,
        float $nominal,
        string $status = Transaction::STATUS_SUCCESS,
    ): void {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'product' => $product,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    /**
     * September 2026:
     * Paket CPNS: 1 Sep = 3 trx (100rb, 150rb, 200rb), 2 Sep = 1 trx (350rb, App B) -> 4 trx, 800rb, 2 hari aktif.
     * Paket BUMN: 10 Sep = 2 trx (100rb, 100rb) -> 2 trx, 200rb, 1 hari aktif.
     */
    private function seedTransactions(): void
    {
        $this->transaction($this->platformA, '2026-09-01', 'Paket CPNS', 100000);
        $this->transaction($this->platformA, '2026-09-01', 'Paket CPNS', 150000);
        $this->transaction($this->platformA, '2026-09-01', 'Paket CPNS', 200000);
        $this->transaction($this->platformB, '2026-09-02', 'Paket CPNS', 350000);
        $this->transaction($this->platformA, '2026-09-10', 'Paket BUMN', 100000);
        $this->transaction($this->platformA, '2026-09-10', 'Paket BUMN', 100000);

        // Tidak dihitung: bulan lain atau tidak berhasil.
        $this->transaction($this->platformA, '2026-10-01', 'Paket CPNS', 999999);
        $this->transaction($this->platformA, '2026-09-05', 'Paket CPNS', 999999, 'failed');
    }

    public function test_guest_cannot_view_or_export_package_detail(): void
    {
        $this->getJson(self::URL.'?year=2026')->assertUnauthorized();
        $this->getJson(self::EXPORT_URL.'?year=2026')->assertUnauthorized();
    }

    public function test_employee_can_view_and_export_package_detail(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee);
        $this->getJson(self::URL.'?year=2026')->assertOk();
        $this->get(self::EXPORT_URL.'?year=2026')->assertOk();
    }

    public function test_it_calculates_price_and_daily_transaction_statistics_per_package(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.filter', [
                'platform' => null,
                'year' => 2026,
                'month' => 9,
                'search' => null,
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ])
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.totals', ['packages' => 2, 'revenue' => 1000000, 'transactions' => 6])
            ->assertJsonPath('data.packages.0', [
                'name' => 'Paket CPNS',
                'revenue' => 800000,
                'transactions' => 4,
                'low_price' => 100000,
                'avg_price' => 200000,
                'high_price' => 350000,
                'low_trx' => 1,
                'avg_trx' => 2,
                'high_trx' => 3,
                'active_days' => 2,
            ])
            ->assertJsonPath('data.packages.1', [
                'name' => 'Paket BUMN',
                'revenue' => 200000,
                'transactions' => 2,
                'low_price' => 100000,
                'avg_price' => 100000,
                'high_price' => 100000,
                'low_trx' => 2,
                'avg_trx' => 2,
                'high_trx' => 2,
                'active_days' => 1,
            ]);
    }

    public function test_platform_filter_only_counts_that_platform(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?platform=app-a&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.packages.0.name', 'Paket CPNS')
            ->assertJsonPath('data.packages.0.transactions', 3)
            ->assertJsonPath('data.packages.0.high_price', 200000)
            ->assertJsonPath('data.packages.0.avg_trx', 3)
            ->assertJsonPath('data.packages.0.active_days', 1);
    }

    public function test_without_month_it_covers_the_whole_year(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026')
            ->assertOk()
            ->assertJsonPath('data.filter.date_to', '2026-12-31')
            ->assertJsonPath('data.packages.0.transactions', 5)
            ->assertJsonPath('data.packages.0.high_price', 999999)
            ->assertJsonPath('data.packages.0.active_days', 3);
    }

    public function test_search_matches_package_name_case_insensitively(): void
    {
        $this->seedTransactions();
        $this->transaction($this->platformA, '2026-09-11', 'Diskon 100% Paket', 50000);

        $this->actingAs($this->manager);

        $this->getJson(self::URL.'?year=2026&month=9&search=bumn')
            ->assertOk()
            ->assertJsonPath('data.filter.search', 'bumn')
            ->assertJsonCount(1, 'data.packages')
            ->assertJsonPath('data.packages.0.name', 'Paket BUMN')
            ->assertJsonPath('data.totals.revenue', 200000);

        $this->getJson(self::URL.'?year=2026&month=9&search=100%25')
            ->assertOk()
            ->assertJsonCount(1, 'data.packages')
            ->assertJsonPath('data.packages.0.name', 'Diskon 100% Paket');
    }

    public function test_empty_result_returns_empty_state(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=9&search=tidak-ada')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.packages', [])
            ->assertJsonPath('data.totals', ['packages' => 0, 'revenue' => 0, 'transactions' => 0]);
    }

    public function test_export_downloads_csv_with_the_same_filtered_data(): void
    {
        $this->seedTransactions();
        $this->transaction($this->platformA, '2026-09-12', null, 10000);

        $response = $this->actingAs($this->manager)
            ->get(self::EXPORT_URL.'?platform=app-a&year=2026&month=9')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('detail-performa-paket-app-a-2026-09.csv');

        $lines = preg_split('/\R/', trim($response->streamedContent()));

        $this->assertSame("\u{FEFF}No,Paket,Revenue,Trx,\"Low Price\",\"Avg Price\",\"High Price\",\"Low Trx\",\"Avg Trx\",\"High Trx\"", $lines[0]);
        $this->assertSame('1,"Paket CPNS",450000,3,100000,150000,200000,3,3,3', $lines[1]);
        $this->assertSame('2,"Paket BUMN",200000,2,100000,100000,100000,2,2,2', $lines[2]);
        $this->assertSame('3,"Tanpa nama paket",10000,1,10000,10000,10000,1,1,1', $lines[3]);
        $this->assertCount(4, $lines);
    }

    public function test_export_file_name_without_platform_and_month(): void
    {
        $this->actingAs($this->manager)
            ->get(self::EXPORT_URL.'?year=2026')
            ->assertOk()
            ->assertDownload('detail-performa-paket-semua-platform-2026.csv');
    }

    public function test_it_validates_the_filters(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

        $this->getJson(self::URL.'?year=2026&month=13&platform=unknown&search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'month' => 'Bulan harus antara 1 sampai 12.',
                'platform' => 'Platform tidak ditemukan.',
                'search' => 'Pencarian maksimal 100 karakter.',
            ]);

        $this->getJson(self::EXPORT_URL.'?month=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);
    }
}
