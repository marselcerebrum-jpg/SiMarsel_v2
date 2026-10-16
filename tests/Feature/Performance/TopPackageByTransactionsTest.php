<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopPackageByTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/top-packages/transactions';

    private const CPNS_1Y = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 1 Tahun';

    private const CPNS_6M = 'Bimbel Persiapan Tes SKD CPNS 2026 - Masa Aktif 6 Bulan';

    private const BUMN_2Y = 'Bimbel Persiapan Rekrutmen Bersama BUMN 2026 - Masa Aktif 2 Tahun';

    private const TNI_1Y = 'Bimbel Persiapan Tes Masuk TNI 2026 - Masa Aktif 1 Tahun';

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

    private function transactions(
        int $count,
        Platform $platform,
        string $date,
        string $product,
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
     * CPNS 6 Bulan = 4 trx x 50rb (200rb), BUMN 2 Tahun = 3 trx x 100rb (300rb),
     * CPNS 1 Tahun = 2 trx x 500rb (1jt), TNI 1 Tahun = 1 trx x 400rb.
     */
    private function seedTransactions(): void
    {
        $this->transactions(3, $this->platformA, '2026-10-01', self::CPNS_6M, 50000);
        $this->transactions(1, $this->platformB, '2026-10-02', self::CPNS_6M, 50000);
        $this->transactions(3, $this->platformA, '2026-10-03', self::BUMN_2Y, 100000);
        $this->transactions(2, $this->platformA, '2026-10-04', self::CPNS_1Y, 500000);
        $this->transactions(1, $this->platformB, '2026-10-05', self::TNI_1Y, 400000);

        // Tidak dihitung: bulan lain atau tidak berhasil.
        $this->transactions(5, $this->platformA, '2026-09-30', self::TNI_1Y, 400000);
        $this->transactions(5, $this->platformA, '2026-10-06', self::TNI_1Y, 400000, 'failed');
    }

    public function test_guest_cannot_view_top_packages_by_transactions(): void
    {
        $this->getJson(self::URL.'?year=2026')->assertUnauthorized();
    }

    public function test_employee_can_view_top_packages_by_transactions(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee)->getJson(self::URL.'?year=2026')->assertOk();
    }

    public function test_it_ranks_packages_by_transaction_count(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.rank_by', 'transactions')
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.totals', ['packages' => 4, 'revenue' => 1900000, 'transactions' => 10])
            ->assertJsonPath('data.packages.0', [
                'rank' => 1,
                'product' => self::CPNS_6M,
                'name' => 'Bimbel Persiapan Tes SKD CPNS 2026',
                'active_period' => '6 Bulan',
                'revenue' => 200000,
                'transactions' => 4,
                'contribution_percentage' => 40,
            ])
            ->assertJsonPath('data.packages.1.product', self::BUMN_2Y)
            ->assertJsonPath('data.packages.1.contribution_percentage', 30)
            ->assertJsonPath('data.packages.2.product', self::CPNS_1Y)
            ->assertJsonPath('data.packages.3.product', self::TNI_1Y)
            ->assertJsonPath('data.labels.0', 'Bimbel Persiapan Tes SKD CPNS 2026 (6 Bulan)')
            ->assertJsonPath('data.datasets.0', ['label' => 'Jumlah Transaksi', 'data' => [4, 3, 2, 1]]);
    }

    public function test_ranking_differs_from_revenue_ranking(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson('/api/performance/top-packages?year=2026&month=10')
            ->assertJsonPath('data.rank_by', 'revenue')
            ->assertJsonPath('data.packages.0.product', self::CPNS_1Y);

        $this->getJson(self::URL.'?year=2026&month=10')
            ->assertJsonPath('data.packages.0.product', self::CPNS_6M);
    }

    public function test_equal_transaction_count_is_ordered_by_revenue(): void
    {
        $this->transactions(2, $this->platformA, '2026-10-01', self::CPNS_6M, 50000);
        $this->transactions(2, $this->platformA, '2026-10-01', self::CPNS_1Y, 500000);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=10')
            ->assertOk()
            ->assertJsonPath('data.packages.0.product', self::CPNS_1Y)
            ->assertJsonPath('data.packages.1.product', self::CPNS_6M);
    }

    public function test_limit_and_platform_filters_are_applied(): void
    {
        $this->seedTransactions();
        $this->actingAs($this->manager);

        $this->getJson(self::URL.'?year=2026&month=10&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.packages')
            ->assertJsonPath('data.totals.transactions', 10);

        $this->getJson(self::URL.'?platform=app-b&year=2026&month=10')
            ->assertOk()
            ->assertJsonCount(2, 'data.packages')
            ->assertJsonPath('data.totals.transactions', 2)
            ->assertJsonPath('data.packages.0.product', self::TNI_1Y);
    }

    public function test_empty_filter_returns_empty_state(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=1')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.packages', [])
            ->assertJsonPath('data.datasets.0', ['label' => 'Jumlah Transaksi', 'data' => []]);
    }

    public function test_it_validates_the_filters(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

        $this->getJson(self::URL.'?year=2026&limit=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit' => 'Jumlah paket harus antara 1 sampai 50.']);
    }
}
