<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodDistributionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/payment-methods';

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

    private function transaction(Platform $platform, string $date, ?string $method, float $nominal, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'payment_method' => $method,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    /**
     * September: bank_transfer Rp550.000 (2), e_wallet Rp300.000 (2), unknown Rp150.000 (2).
     */
    private function seedDistributionData(): void
    {
        $this->transaction($this->platformA, '2026-08-20', 'credit_card', 400000);

        $this->transaction($this->platformA, '2026-09-05', 'bank_transfer', 500000);
        $this->transaction($this->platformA, '2026-09-06', 'e_wallet', 200000);
        $this->transaction($this->platformA, '2026-09-30', ' E_WALLET ', 100000);
        $this->transaction($this->platformA, '2026-09-12', 'credit_card', 999999, 'failed');

        $this->transaction($this->platformB, '2026-09-10', 'bank_transfer', 50000);
        $this->transaction($this->platformB, '2026-09-11', null, 100000);
        $this->transaction($this->platformB, '2026-09-12', '  ', 50000);
    }

    public function test_guest_cannot_view_payment_method_distribution(): void
    {
        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_filters_are_validated_like_the_performance_summary(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=unknown')
            ->assertJsonValidationErrors(['platform' => 'Platform tidak ditemukan.']);
    }

    public function test_each_payment_method_shows_transactions_revenue_and_contribution(): void
    {
        $this->seedDistributionData();

        $response = $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.filter', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'platform' => null])
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.payment_methods.0', [
                'name' => 'bank_transfer',
                'transactions' => 2,
                'revenue' => 550000,
                'transaction_percentage' => 33.33,
                'revenue_percentage' => 55,
            ])
            ->assertJsonPath('data.payment_methods.2', [
                'name' => 'Tidak diketahui',
                'transactions' => 2,
                'revenue' => 150000,
                'transaction_percentage' => 33.33,
                'revenue_percentage' => 15,
            ])
            ->assertJsonPath('data.totals', ['transactions' => 6, 'revenue' => 1000000])
            ->assertJsonPath('data.datasets', [['label' => 'Revenue', 'data' => [550000, 300000, 150000]]]);

        $this->assertSame(
            ['name', 'transactions', 'revenue', 'transaction_percentage', 'revenue_percentage'],
            array_keys($response->json('data.payment_methods.0')),
        );
        $this->assertSame($response->json('data.labels'), array_column($response->json('data.payment_methods'), 'name'));
    }

    public function test_methods_are_merged_regardless_of_case_and_whitespace(): void
    {
        $this->seedDistributionData();

        $method = $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertJsonCount(3, 'data.payment_methods')
            ->json('data.payment_methods.1');

        $this->assertSame('e_wallet', strtolower($method['name']));
        $this->assertSame(2, $method['transactions']);
        $this->assertEquals(300000, $method['revenue']);
        $this->assertEquals(30, $method['revenue_percentage']);
    }

    public function test_failed_transactions_and_other_dates_are_excluded(): void
    {
        $this->seedDistributionData();

        $names = $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->json('data.labels');

        $this->assertNotContains('credit_card', $names);
    }

    public function test_platform_filter_only_counts_the_selected_platform(): void
    {
        $this->seedDistributionData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-b')
            ->assertOk()
            ->assertJsonPath('data.filter.platform', 'app-b')
            ->assertJsonPath('data.payment_methods', [
                ['name' => 'Tidak diketahui', 'transactions' => 2, 'revenue' => 150000, 'transaction_percentage' => 66.67, 'revenue_percentage' => 75],
                ['name' => 'bank_transfer', 'transactions' => 1, 'revenue' => 50000, 'transaction_percentage' => 33.33, 'revenue_percentage' => 25],
            ])
            ->assertJsonPath('data.totals', ['transactions' => 3, 'revenue' => 200000]);
    }

    public function test_distribution_matches_the_performance_summary_totals(): void
    {
        $this->seedDistributionData();

        $summary = $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?date_from=2026-09-01&date_to=2026-09-30')
            ->json('data.kpis');

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertJsonPath('data.totals.revenue', $summary['total_revenue']['value'])
            ->assertJsonPath('data.totals.transactions', $summary['total_transactions']['value']);
    }

    public function test_period_without_transactions_returns_empty_state(): void
    {
        $this->seedDistributionData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.totals', ['transactions' => 0, 'revenue' => 0])
            ->assertJsonPath('data.payment_methods', [])
            ->assertJsonPath('data.labels', [])
            ->assertJsonPath('data.datasets', [['label' => 'Revenue', 'data' => []]]);
    }
}
