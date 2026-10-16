<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopPackageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/top-packages';

    private const CPNS_1Y = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun';

    private const CPNS_6M = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 6 Bulan';

    private const BUMN_2Y = 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026 - Masa Aktif 2 Tahun';

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
     * Oktober 2026: CPNS 1 Tahun = 500rb (2 trx), BUMN 2 Tahun = 300rb, CPNS 6 Bulan = 200rb.
     */
    private function seedTransactions(): void
    {
        $this->transaction($this->platformA, '2026-10-01', self::CPNS_1Y, 300000);
        $this->transaction($this->platformB, '2026-10-02', self::CPNS_1Y, 200000);
        $this->transaction($this->platformA, '2026-10-03', self::BUMN_2Y, 300000);
        $this->transaction($this->platformA, '2026-10-04', self::CPNS_6M, 200000);

        // Tidak dihitung: bulan lain atau tidak berhasil.
        $this->transaction($this->platformA, '2026-09-30', self::CPNS_6M, 999999);
        $this->transaction($this->platformA, '2026-10-05', self::CPNS_6M, 999999, 'failed');
    }

    public function test_guest_cannot_view_top_packages(): void
    {
        $this->getJson(self::URL.'?year=2026')->assertUnauthorized();
    }

    public function test_employee_can_view_top_packages(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee)->getJson(self::URL.'?year=2026')->assertOk();
    }

    public function test_it_ranks_packages_by_revenue_with_name_and_active_period(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.filter', [
                'platform' => null,
                'year' => 2026,
                'month' => 10,
                'limit' => 10,
                'date_from' => '2026-10-01',
                'date_to' => '2026-10-31',
            ])
            ->assertJsonPath('data.rank_by', 'revenue')
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.totals', ['packages' => 3, 'revenue' => 1000000, 'transactions' => 4])
            ->assertJsonPath('data.packages.0', [
                'rank' => 1,
                'product' => self::CPNS_1Y,
                'name' => 'Bimbel Persiapan Tes SKD CPNS 2026',
                'active_period' => '1 Tahun',
                'revenue' => 500000,
                'transactions' => 2,
                'contribution_percentage' => 50,
            ])
            ->assertJsonPath('data.packages.1.name', 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026')
            ->assertJsonPath('data.packages.1.active_period', '2 Tahun')
            ->assertJsonPath('data.packages.2.active_period', '6 Bulan')
            ->assertJsonPath('data.labels', [
                'Bimbel Persiapan Tes SKD CPNS 2026 (1 Tahun)',
                'Bimbel Persiapan Rekrutmen Bersama BUMN 2026 (2 Tahun)',
                'Bimbel Persiapan Tes SKD CPNS 2026 (6 Bulan)',
            ])
            ->assertJsonPath('data.datasets.0', ['label' => 'Total Pendapatan', 'data' => [500000, 300000, 200000]]);
    }

    public function test_limit_returns_only_the_top_packages_but_totals_cover_all(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=10&limit=2')
            ->assertOk()
            ->assertJsonPath('data.filter.limit', 2)
            ->assertJsonCount(2, 'data.packages')
            ->assertJsonCount(2, 'data.labels')
            ->assertJsonPath('data.totals', ['packages' => 3, 'revenue' => 1000000, 'transactions' => 4])
            ->assertJsonPath('data.packages.1.contribution_percentage', 30);
    }

    public function test_platform_and_year_filters_are_applied(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson(self::URL.'?platform=app-b&year=2026&month=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.packages')
            ->assertJsonPath('data.packages.0.revenue', 200000);

        $this->getJson(self::URL.'?year=2026')
            ->assertOk()
            ->assertJsonPath('data.filter.date_to', '2026-12-31')
            ->assertJsonPath('data.packages.0.product', self::CPNS_6M)
            ->assertJsonPath('data.packages.0.revenue', 1199999);
    }

    public function test_products_without_active_period_keep_their_full_name(): void
    {
        $this->transaction($this->platformA, '2026-10-01', 'Paket Spesial Tanpa Durasi', 100000);
        $this->transaction($this->platformA, '2026-10-02', null, 50000);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.packages.0.name', 'Paket Spesial Tanpa Durasi')
            ->assertJsonPath('data.packages.0.active_period', null)
            ->assertJsonPath('data.packages.1.name', null)
            ->assertJsonPath('data.labels', ['Paket Spesial Tanpa Durasi', 'Tanpa nama paket']);
    }

    public function test_empty_filter_returns_empty_state(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=1')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.packages', [])
            ->assertJsonPath('data.totals', ['packages' => 0, 'revenue' => 0, 'transactions' => 0]);
    }

    public function test_it_validates_the_filters(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

        $this->getJson(self::URL.'?year=2026&limit=51&platform=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'limit' => 'Jumlah paket harus antara 1 sampai 50.',
                'platform' => 'Platform tidak ditemukan.',
            ]);

        $this->getJson(self::URL.'?year=2026&limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    }
}
