<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/summary';

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
        ?string $email,
        float $nominal,
        string $type = Transaction::TYPE_FIRST,
        string $status = Transaction::STATUS_SUCCESS,
    ): void {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'customer_email' => $email,
            'nominal' => $nominal,
            'product_type' => $type,
            'status_message' => $status,
        ]);
    }

    private function downloader(Platform $platform, string $date, int $total): void
    {
        Downloader::factory()->for($platform)->create(['date' => $date, 'total' => $total]);
    }

    /**
     * Current period: 2026-09-01 - 2026-09-30, previous period: 2026-08-02 - 2026-08-31.
     */
    private function seedPerformanceData(): void
    {
        $this->transaction($this->platformA, '2026-07-15', 'alice@example.com', 100000);

        $this->transaction($this->platformA, '2026-08-20', 'dave@example.com', 500000);
        $this->downloader($this->platformA, '2026-08-31', 50);

        $this->transaction($this->platformA, '2026-09-05', 'alice@example.com', 200000, Transaction::TYPE_RENEWAL);
        $this->transaction($this->platformA, '2026-09-10', ' ALICE@example.com ', 100000, Transaction::TYPE_RENEWAL);
        $this->transaction($this->platformA, '2026-09-30', 'bob@example.com', 300000);
        $this->transaction($this->platformA, '2026-09-15', null, 400000);
        $this->transaction($this->platformA, '2026-09-12', 'carol@example.com', 999999, Transaction::TYPE_RENEWAL, 'failed');
        $this->downloader($this->platformA, '2026-09-01', 30);
        $this->downloader($this->platformA, '2026-09-30', 70);

        $this->transaction($this->platformB, '2026-09-10', 'erin@example.com', 50000);
        $this->downloader($this->platformB, '2026-09-10', 20);
    }

    public function test_guest_cannot_view_performance_summary(): void
    {
        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_employee_can_view_performance_summary(): void
    {
        $employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);

        $this->actingAs($employee)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk();
    }

    public function test_filters_are_validated(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        $this->getJson(self::URL.'?date_from=01-09-2026&date_to=2026-09-30')
            ->assertJsonValidationErrors(['date_from' => 'Tanggal mulai harus berformat YYYY-MM-DD.']);

        $this->getJson(self::URL.'?date_from=2026-09-30&date_to=2026-09-01')
            ->assertJsonValidationErrors(['date_to' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=unknown')
            ->assertJsonValidationErrors(['platform' => 'Platform tidak ditemukan.']);
    }

    public function test_kpis_are_calculated_for_selected_platform_and_compared_with_previous_period(): void
    {
        $this->seedPerformanceData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-a')
            ->assertOk()
            ->assertJson(['data' => [
                'filter' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'platform' => 'app-a'],
                'previous_period' => ['date_from' => '2026-08-02', 'date_to' => '2026-08-31'],
                'is_empty' => false,
                'message' => null,
                'kpis' => [
                    'total_revenue' => ['value' => 1000000, 'previous_value' => 500000, 'change_percentage' => 100, 'trend' => 'up'],
                    'total_transactions' => ['value' => 4, 'previous_value' => 1, 'change_percentage' => 300, 'trend' => 'up'],
                    'total_downloaders' => ['value' => 100, 'previous_value' => 50, 'change_percentage' => 100, 'trend' => 'up'],
                    'unique_users' => ['value' => 2, 'previous_value' => 1, 'change_percentage' => 100, 'trend' => 'up'],
                    'new_users' => ['value' => 2, 'previous_value' => 1, 'change_percentage' => 100, 'trend' => 'up'],
                    'repeat_orders' => ['value' => 2, 'previous_value' => 0, 'change_percentage' => null, 'trend' => 'up'],
                    'average_order_value' => ['value' => 250000, 'previous_value' => 500000, 'change_percentage' => -50, 'trend' => 'down'],
                    'conversion_rate' => ['value' => 4, 'previous_value' => 2, 'change_percentage' => 100, 'trend' => 'up'],
                ],
            ]])
            ->assertJsonPath('data.kpis.repeat_orders.change_percentage', null)
            ->assertJsonPath('data.kpis.new_users.change_percentage', 100)
            ->assertJsonMissingPath('data.kpis.repeat_order_users')
            ->assertJsonPath('data.message', null);
    }

    public function test_all_platforms_are_included_when_platform_filter_is_empty(): void
    {
        $this->seedPerformanceData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJson(['data' => [
                'filter' => ['platform' => null],
                'kpis' => [
                    'total_revenue' => ['value' => 1050000],
                    'total_transactions' => ['value' => 5],
                    'total_downloaders' => ['value' => 120],
                    'unique_users' => ['value' => 3],
                    'new_users' => ['value' => 3],
                    'repeat_orders' => ['value' => 2],
                    'average_order_value' => ['value' => 210000],
                    'conversion_rate' => ['value' => 4.17],
                ],
            ]]);
    }

    public function test_renewals_and_first_purchases_without_success_status_are_not_counted(): void
    {
        $this->transaction($this->platformA, '2026-09-05', 'alice@example.com', 100000, Transaction::TYPE_RENEWAL);
        $this->transaction($this->platformA, '2026-09-06', 'alice@example.com', 100000, Transaction::TYPE_RENEWAL, 'failed');
        $this->transaction($this->platformA, '2026-09-07', 'bob@example.com', 100000, Transaction::TYPE_FIRST, 'pending');
        $this->downloader($this->platformA, '2026-09-05', 10);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-a')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_transactions.value', 1)
            ->assertJsonPath('data.kpis.repeat_orders.value', 1)
            ->assertJsonPath('data.kpis.new_users.value', 0)
            ->assertJsonPath('data.kpis.conversion_rate.value', 10);
    }

    public function test_empty_period_returns_informative_empty_state(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJson(['data' => [
                'is_empty' => true,
                'message' => 'Data tidak tersedia untuk filter yang dipilih.',
                'kpis' => [
                    'total_revenue' => ['value' => 0, 'previous_value' => 0, 'change_percentage' => null, 'trend' => 'same'],
                    'total_transactions' => ['value' => 0],
                    'average_order_value' => ['value' => null, 'previous_value' => null, 'trend' => 'same'],
                    'conversion_rate' => ['value' => null, 'previous_value' => null, 'trend' => 'same'],
                ],
            ]])
            ->assertJsonPath('data.kpis.total_revenue.change_percentage', null)
            ->assertJsonPath('data.kpis.average_order_value.value', null)
            ->assertJsonPath('data.kpis.conversion_rate.value', null)
            ->assertJsonPath('data.kpis.total_transactions.value', 0);
    }

    public function test_single_day_period_compares_with_previous_day(): void
    {
        $this->transaction($this->platformA, '2026-09-09', 'a@example.com', 100000);
        $this->transaction($this->platformA, '2026-09-10', 'b@example.com', 100000);
        $this->transaction($this->platformA, '2026-09-10', 'c@example.com', 100000);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-10&date_to=2026-09-10')
            ->assertOk()
            ->assertJson(['data' => [
                'previous_period' => ['date_from' => '2026-09-09', 'date_to' => '2026-09-09'],
                'kpis' => [
                    'total_transactions' => ['value' => 2, 'previous_value' => 1, 'change_percentage' => 100, 'trend' => 'up'],
                ],
            ]]);
    }
}
