<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/products';

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
        string $type = Transaction::TYPE_FIRST,
        ?string $email = null,
        string $status = Transaction::STATUS_SUCCESS,
    ): void {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'product' => $product,
            'nominal' => $nominal,
            'product_type' => $type,
            'customer_email' => $email,
            'status_message' => $status,
        ]);
    }

    /**
     * September 2026: Paket CPNS = 3 trx / 600.000 (App A dan B), Paket BUMN = 1 trx / 200.000 (App A).
     */
    private function seedTransactions(): void
    {
        $this->transaction($this->platformA, '2026-09-01', 'Paket CPNS', 100000, Transaction::TYPE_FIRST, 'alice@example.com');
        $this->transaction($this->platformA, '2026-09-15', 'Paket CPNS', 200000, Transaction::TYPE_RENEWAL, ' ALICE@example.com ');
        $this->transaction($this->platformB, '2026-09-30', 'Paket CPNS', 300000, Transaction::TYPE_RENEWAL, 'bob@example.com');
        $this->transaction($this->platformA, '2026-09-10', 'Paket BUMN', 200000, Transaction::TYPE_FIRST, null);

        // Bulan lain di tahun yang sama.
        $this->transaction($this->platformB, '2026-10-05', 'Paket TNI', 500000);

        // Tidak dihitung: tahun lain atau tidak berhasil.
        $this->transaction($this->platformA, '2025-09-10', 'Paket CPNS', 999999);
        $this->transaction($this->platformA, '2026-09-12', 'Paket CPNS', 999999, Transaction::TYPE_FIRST, null, 'failed');
    }

    public function test_guest_cannot_view_product_performance(): void
    {
        $this->getJson(self::URL.'?year=2026')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Silakan login terlebih dahulu.']);
    }

    public function test_employee_can_view_product_performance(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee)->getJson(self::URL.'?year=2026')->assertOk();
    }

    public function test_it_returns_products_for_the_selected_year_and_month(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.filter', [
                'platform' => null,
                'year' => 2026,
                'month' => 9,
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ])
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.totals', [
                'products' => 2,
                'revenue' => 800000,
                'transactions' => 4,
                'new_users' => 2,
                'repeat_orders' => 2,
            ])
            ->assertJsonPath('data.products.0', [
                'name' => 'Paket CPNS',
                'revenue' => 600000,
                'transactions' => 3,
                'new_users' => 1,
                'repeat_orders' => 2,
                'users' => 2,
                'contribution_percentage' => 75,
            ])
            ->assertJsonPath('data.products.1.name', 'Paket BUMN')
            ->assertJsonPath('data.products.1.users', 0)
            ->assertJsonPath('data.products.1.contribution_percentage', 25)
            ->assertJsonPath('data.labels', ['Paket CPNS', 'Paket BUMN'])
            ->assertJsonPath('data.datasets.0', ['label' => 'Revenue', 'data' => [600000, 200000]]);
    }

    public function test_without_month_it_covers_the_whole_year(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026')
            ->assertOk()
            ->assertJsonPath('data.filter.month', null)
            ->assertJsonPath('data.filter.date_from', '2026-01-01')
            ->assertJsonPath('data.filter.date_to', '2026-12-31')
            ->assertJsonPath('data.totals.products', 3)
            ->assertJsonPath('data.totals.revenue', 1300000)
            ->assertJsonPath('data.labels', ['Paket CPNS', 'Paket TNI', 'Paket BUMN']);
    }

    public function test_platform_filter_only_counts_that_platform(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?platform=app-a&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.filter.platform', 'app-a')
            ->assertJsonPath('data.totals.revenue', 500000)
            ->assertJsonPath('data.products.0.name', 'Paket CPNS')
            ->assertJsonPath('data.products.0.revenue', 300000)
            ->assertJsonPath('data.products.0.transactions', 2)
            ->assertJsonPath('data.products.0.users', 1)
            ->assertJsonPath('data.products.1.name', 'Paket BUMN');
    }

    public function test_transactions_without_product_name_are_grouped_together(): void
    {
        $this->transaction($this->platformA, '2026-09-01', null, 100000);
        $this->transaction($this->platformA, '2026-09-02', null, 50000);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?year=2026&month=9')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', null)
            ->assertJsonPath('data.products.0.transactions', 2);
    }

    public function test_empty_filter_returns_empty_state(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?platform=app-b&year=2026&month=1')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.totals.products', 0)
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.labels', []);
    }

    public function test_it_validates_the_filters(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun wajib diisi.']);

        $this->getJson(self::URL.'?year=26&month=13&platform=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'year' => 'Tahun harus 4 digit, misalnya 2026.',
                'month' => 'Bulan harus antara 1 sampai 12.',
                'platform' => 'Platform tidak ditemukan.',
            ]);

        $this->getJson(self::URL.'?year=abcd')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'Tahun harus berupa angka.']);
    }
}
