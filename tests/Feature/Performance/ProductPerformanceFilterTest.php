<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPerformanceFilterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/products/filters';

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
    }

    private function transaction(Platform $platform, string $date, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create(['paid_date' => $date, 'status_message' => $status]);
    }

    private function seedTransactions(): void
    {
        $this->transaction($this->platformA, '2025-12-31');
        $this->transaction($this->platformA, '2026-09-01');
        $this->transaction($this->platformA, '2026-09-15');
        $this->transaction($this->platformB, '2026-02-10');
        $this->transaction($this->platformB, '2026-10-05');

        // Tidak dihitung: transaksi gagal.
        $this->transaction($this->platformA, '2024-05-05', 'failed');
    }

    public function test_guest_cannot_view_filter_options(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_employee_can_view_filter_options(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee)->getJson(self::URL)->assertOk();
    }

    public function test_it_returns_platforms_and_periods_with_successful_transactions(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'platform' => null,
                    'platforms' => [
                        ['slug' => 'app-a', 'name' => 'App A'],
                        ['slug' => 'app-b', 'name' => 'App B'],
                    ],
                    'years' => [2026, 2025],
                    'months' => [
                        '2026' => [2, 9, 10],
                        '2025' => [12],
                    ],
                    'default' => ['year' => 2026, 'month' => 10],
                ],
            ]);
    }

    public function test_platform_narrows_years_and_months_but_keeps_every_platform_option(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?platform=app-a')
            ->assertOk()
            ->assertJsonPath('data.platform', 'app-a')
            ->assertJsonCount(2, 'data.platforms')
            ->assertJsonPath('data.years', [2026, 2025])
            ->assertJsonPath('data.months', ['2026' => [9], '2025' => [12]])
            ->assertJsonPath('data.default', ['year' => 2026, 'month' => 9]);
    }

    public function test_without_transactions_periods_are_empty(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data.platforms')
            ->assertJsonPath('data.years', [])
            ->assertJsonPath('data.default', ['year' => null, 'month' => null]);

        $this->assertStringContainsString('"months":{}', $response->getContent());
    }

    public function test_unknown_platform_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?platform=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform' => 'Platform tidak ditemukan.']);
    }
}
