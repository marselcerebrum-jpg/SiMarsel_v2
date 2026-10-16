<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformContributionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/platform-contribution';

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
        Platform::factory()->create(['slug' => 'app-c', 'name' => 'App C']);
    }

    private function transaction(Platform $platform, string $date, ?string $email, float $nominal, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'customer_email' => $email,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    /**
     * App A: Rp1.000.000, 4 transactions, 2 users. App B: Rp150.000, 2 transactions, 2 users. App C: no data.
     */
    private function seedContributionData(): void
    {
        $this->transaction($this->platformA, '2026-08-20', 'dave@example.com', 500000);

        $this->transaction($this->platformA, '2026-09-05', 'alice@example.com', 200000);
        $this->transaction($this->platformA, '2026-09-10', ' ALICE@example.com ', 100000);
        $this->transaction($this->platformA, '2026-09-30', 'bob@example.com', 300000);
        $this->transaction($this->platformA, '2026-09-15', null, 400000);
        $this->transaction($this->platformA, '2026-09-12', 'carol@example.com', 999999, 'failed');

        $this->transaction($this->platformB, '2026-09-10', 'erin@example.com', 50000);
        $this->transaction($this->platformB, '2026-09-11', 'alice@example.com', 100000);
    }

    public function test_guest_cannot_view_platform_contribution(): void
    {
        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertUnauthorized();
    }

    public function test_filters_are_validated_like_the_performance_summary(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::URL.'?date_from=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to' => 'Tanggal akhir wajib diisi.']);

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=unknown')
            ->assertJsonValidationErrors(['platform' => 'Platform tidak ditemukan.']);
    }

    public function test_each_platform_shows_revenue_transactions_users_and_contribution(): void
    {
        $this->seedContributionData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.filter.mode', 'date_range')
            ->assertJsonPath('data.filter.date_from', '2026-09-01')
            ->assertJsonPath('data.filter.date_to', '2026-09-30')
            ->assertJsonPath('data.filter.platforms', [])
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.platforms', [
                ['rank' => 1, 'slug' => 'app-a', 'name' => 'App A', 'revenue' => 1000000, 'transactions' => 4, 'users' => 2, 'contribution_percentage' => 87],
                ['rank' => 2, 'slug' => 'app-b', 'name' => 'App B', 'revenue' => 150000, 'transactions' => 2, 'users' => 2, 'contribution_percentage' => 13],
                ['rank' => 3, 'slug' => 'app-c', 'name' => 'App C', 'revenue' => 0, 'transactions' => 0, 'users' => 0, 'contribution_percentage' => 0],
            ])
            ->assertJsonPath('data.totals', ['revenue' => 1150000, 'transactions' => 6, 'users' => 4, 'platforms' => 3])
            ->assertJsonPath('data.labels', ['App A', 'App B', 'App C'])
            ->assertJsonPath('data.datasets', [['label' => 'Revenue', 'data' => [1000000, 150000, 0]]]);
    }

    public function test_platform_filter_shows_only_the_selected_platform(): void
    {
        $this->seedContributionData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-b')
            ->assertOk()
            ->assertJsonCount(1, 'data.platforms')
            ->assertJsonPath('data.platforms.0.slug', 'app-b')
            ->assertJsonPath('data.platforms.0.rank', 1)
            ->assertJsonPath('data.platforms.0.contribution_percentage', 100)
            ->assertJsonPath('data.totals.platforms', 1);
    }

    public function test_contribution_percentage_is_rounded_to_one_decimal(): void
    {
        $this->transaction($this->platformA, '2026-09-05', 'alice@example.com', 100000);
        $this->transaction($this->platformB, '2026-09-05', 'bob@example.com', 200000);

        // 100000 / 300000 = 33.333...%, 200000 / 300000 = 66.666...%.
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertJsonPath('data.platforms.0.contribution_percentage', 66.7)
            ->assertJsonPath('data.platforms.1.contribution_percentage', 33.3);
    }

    public function test_contribution_matches_the_performance_summary_totals(): void
    {
        $this->seedContributionData();

        $summary = $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?date_from=2026-09-01&date_to=2026-09-30')
            ->json('data.kpis');

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertJsonPath('data.totals.revenue', $summary['total_revenue']['value'])
            ->assertJsonPath('data.totals.transactions', $summary['total_transactions']['value']);
    }

    public function test_period_without_transactions_returns_empty_state(): void
    {
        $this->seedContributionData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Tidak ada data kontribusi platform untuk filter yang dipilih.')
            ->assertJsonPath('data.totals', ['revenue' => 0, 'transactions' => 0, 'users' => 0, 'platforms' => 3])
            ->assertJsonPath('data.datasets.0.data', [0, 0, 0])
            ->assertJsonPath('data.platforms.*.contribution_percentage', [null, null, null])
            ->assertJsonPath('data.platforms.*.rank', [1, 2, 3]);
    }
}
