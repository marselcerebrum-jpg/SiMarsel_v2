<?php

namespace Tests\Feature\Product;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const CPNS_1Y = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun';

    private const CPNS_6M = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 6 Bulan';

    private const BUMN_2Y = 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026 - Masa Aktif 2 Tahun';

    private const DATA_ENDPOINTS = [
        '/api/products/top-packages/revenue',
        '/api/products/top-packages/volume',
        '/api/products/performance',
    ];

    private Account $manager;

    private Account $employee;

    private Platform $asn;

    private Platform $bumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
        $this->bumn = Platform::factory()->create(['slug' => 'jadibumn', 'name' => 'JadiBUMN']);
        $this->asn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JadiASN']);
    }

    private function transactions(
        int $count,
        Platform $platform,
        string $date,
        ?string $product,
        float $nominal,
        string $status = Transaction::STATUS_SUCCESS,
    ): void {
        Transaction::factory()->count($count)->for($platform)->create([
            'paid_date' => $date,
            'product' => $product,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    /**
     * Oktober 2026:
     * JadiASN  : CPNS 1 Tahun = 1 Okt 2 x 300rb, 2 Okt 1 x 400rb -> 3 trx, 1jt
     *            CPNS 6 Bulan = 3 Okt 4 x 50rb                     -> 4 trx, 200rb
     * JadiBUMN : BUMN 2 Tahun = 4 Okt 1 x 500rb                    -> 1 trx, 500rb
     * September 2026, JadiASN: CPNS 6 Bulan 1 x 50rb.
     */
    private function seedTransactions(): void
    {
        $this->transactions(2, $this->asn, '2026-10-01', self::CPNS_1Y, 300000);
        $this->transactions(1, $this->asn, '2026-10-02', self::CPNS_1Y, 400000);
        $this->transactions(4, $this->asn, '2026-10-03', self::CPNS_6M, 50000);
        $this->transactions(1, $this->bumn, '2026-10-04', self::BUMN_2Y, 500000);
        $this->transactions(1, $this->asn, '2026-09-15', self::CPNS_6M, 50000);

        // Tidak dihitung: transaksi gagal.
        $this->transactions(3, $this->asn, '2026-10-05', self::CPNS_1Y, 999999, 'failed');
    }

    public function test_guest_cannot_access_product_overview_api(): void
    {
        $this->getJson('/api/products/filters')->assertUnauthorized();

        foreach (self::DATA_ENDPOINTS as $url) {
            $this->getJson($url.'?year=2026')->assertUnauthorized();
        }
    }

    public function test_manager_and_employee_can_access_product_overview_api(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account);
            $this->getJson('/api/products/filters')->assertOk();

            foreach (self::DATA_ENDPOINTS as $url) {
                $this->getJson($url.'?year=2026')->assertOk();
            }
        }
    }

    // Pilihan filter Source App, Tahun, dan Bulan

    public function test_filters_return_platform_year_and_month_options(): void
    {
        $this->seedTransactions();
        $this->transactions(1, $this->bumn, '2025-12-31', self::BUMN_2Y, 500000);

        $this->actingAs($this->manager)
            ->getJson('/api/products/filters')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'platforms' => [
                        ['slug' => 'jadiasn', 'name' => 'JadiASN'],
                        ['slug' => 'jadibumn', 'name' => 'JadiBUMN'],
                    ],
                    'years' => [2026, 2025],
                    'months' => ['2026' => [9, 10], '2025' => [12]],
                ],
            ]);
    }

    public function test_filters_can_be_narrowed_to_one_platform(): void
    {
        $this->seedTransactions();
        $this->transactions(1, $this->bumn, '2025-12-31', self::BUMN_2Y, 500000);

        $this->actingAs($this->manager)
            ->getJson('/api/products/filters?platform=jadiasn')
            ->assertOk()
            ->assertJsonCount(2, 'data.platforms')
            ->assertJsonPath('data.years', [2026])
            ->assertJsonPath('data.months', ['2026' => [9, 10]]);
    }

    public function test_filters_fall_back_to_current_year_without_transactions(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/products/filters')
            ->assertOk()
            ->assertJsonPath('data.years', [CarbonImmutable::now()->year]);
    }

    // Data ringkasan produk menyesuaikan filter

    public function test_top_packages_by_revenue(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson('/api/products/top-packages/revenue?year=2026&month=10')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['name' => 'Bimbel Persiapan Tes SKD CPNS 2026', 'active_period' => '1 Tahun', 'product' => self::CPNS_1Y, 'revenue' => 1000000, 'transactions' => 3],
                    ['name' => 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026', 'active_period' => '2 Tahun', 'product' => self::BUMN_2Y, 'revenue' => 500000, 'transactions' => 1],
                    ['name' => 'Bimbel Persiapan Tes SKD CPNS 2026', 'active_period' => '6 Bulan', 'product' => self::CPNS_6M, 'revenue' => 200000, 'transactions' => 4],
                ],
            ]);
    }

    public function test_top_packages_by_volume(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson('/api/products/top-packages/volume?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.*.product', [self::CPNS_6M, self::CPNS_1Y, self::BUMN_2Y])
            ->assertJsonPath('data.0.transactions', 4)
            ->assertJsonPath('data.0.active_period', '6 Bulan');
    }

    public function test_top_packages_limit(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson('/api/products/top-packages/revenue?year=2026&month=10&limit=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/products/top-packages/volume?year=2026&month=10&limit=1')->assertJsonCount(1, 'data');
    }

    public function test_package_performance(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson('/api/products/performance?year=2026&month=10')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', [
                'name' => self::CPNS_1Y,
                'revenue' => 1000000,
                'trx' => 3,
                'low_price' => 300000,
                'low_trx' => 1,
                'avg_price' => 333333.33,
                'avg_trx' => 1.5,
                'high_price' => 400000,
                'high_trx' => 2,
            ])
            ->assertJsonPath('data.2.name', self::CPNS_6M)
            ->assertJsonPath('data.2.low_trx', 4)
            ->assertJsonPath('data.2.high_trx', 4);
    }

    public function test_month_filter_changes_the_data(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson('/api/products/performance?year=2026&month=9')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', self::CPNS_6M)
            ->assertJsonPath('data.0.trx', 1);

        // Tanpa bulan: satu tahun penuh (September + Oktober).
        $this->getJson('/api/products/performance?year=2026')
            ->assertOk()
            ->assertJsonPath('data.2.name', self::CPNS_6M)
            ->assertJsonPath('data.2.trx', 5);
    }

    public function test_platform_filter_changes_the_data(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson('/api/products/top-packages/revenue?year=2026&platform=jadibumn')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product', self::BUMN_2Y);
    }

    public function test_platform_year_and_month_can_be_combined(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $query = '?platform=jadiasn&year=2026&month=10';

        $this->getJson('/api/products/top-packages/revenue'.$query)
            ->assertJsonPath('data.*.product', [self::CPNS_1Y, self::CPNS_6M]);
        $this->getJson('/api/products/top-packages/volume'.$query)
            ->assertJsonPath('data.*.product', [self::CPNS_6M, self::CPNS_1Y]);
        $this->getJson('/api/products/performance'.$query)
            ->assertJsonPath('data.*.name', [self::CPNS_1Y, self::CPNS_6M]);
    }

    public function test_package_without_name_and_active_period_never_returns_null_name(): void
    {
        $this->transactions(1, $this->asn, '2026-10-01', null, 100000);
        $this->transactions(1, $this->asn, '2026-10-02', 'Paket Spesial', 50000);
        $this->actingAs($this->manager);

        $this->getJson('/api/products/top-packages/revenue?year=2026')
            ->assertJsonPath('data.0', ['name' => 'Tanpa nama paket', 'active_period' => null, 'product' => 'Tanpa nama paket', 'revenue' => 100000, 'transactions' => 1])
            ->assertJsonPath('data.1.name', 'Paket Spesial')
            ->assertJsonPath('data.1.active_period', null);
        $this->getJson('/api/products/performance?year=2026')
            ->assertJsonPath('data.0.name', 'Tanpa nama paket');
    }

    // Kondisi tidak ada data

    public function test_empty_filter_returns_empty_list(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        foreach (self::DATA_ENDPOINTS as $url) {
            $this->getJson($url.'?platform=jadibumn&year=2026&month=9')
                ->assertOk()
                ->assertExactJson(['data' => []]);
        }
    }

    public function test_it_validates_the_filters(): void
    {
        $this->actingAs($this->manager);

        foreach (self::DATA_ENDPOINTS as $url) {
            $this->getJson($url)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

            $this->getJson($url.'?year=26&month=13&platform=unknown')
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'year' => 'Tahun harus 4 digit, misalnya 2026.',
                    'month' => 'Bulan harus antara 1 sampai 12.',
                    'platform' => 'Platform tidak ditemukan.',
                ]);
        }

        $this->getJson('/api/products/top-packages/revenue?year=2026&limit=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit' => 'Jumlah paket harus antara 1 sampai 50.']);
        $this->getJson('/api/products/filters?platform=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform' => 'Platform tidak ditemukan.']);
    }
}
