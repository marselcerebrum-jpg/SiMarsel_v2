<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTimeDistributionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/purchase-times';

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

    private function transaction(Platform $platform, string $date, ?string $time, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform)->create([
            'paid_date' => $date,
            'paid_time' => $time,
            'status_message' => $status,
        ]);
    }

    /**
     * September: dawn 2, morning 2, midday 1, afternoon 1, night 2, unknown 1.
     */
    private function seedPurchaseTimeData(): void
    {
        $this->transaction($this->platformA, '2026-08-20', '08:00:00');

        $this->transaction($this->platformA, '2026-09-05', '05:59:59');
        $this->transaction($this->platformA, '2026-09-05', '06:00:00');
        $this->transaction($this->platformA, '2026-09-06', '10:30:00');
        $this->transaction($this->platformA, '2026-09-07', '14:59:59');
        $this->transaction($this->platformA, '2026-09-08', '17:00:00');
        $this->transaction($this->platformA, '2026-09-30', '23:59:59');
        $this->transaction($this->platformA, '2026-09-10', null);
        $this->transaction($this->platformA, '2026-09-12', '20:00:00', 'failed');

        $this->transaction($this->platformB, '2026-09-10', '18:00:00');
        $this->transaction($this->platformB, '2026-09-11', '00:00:00');
    }

    public function test_guest_cannot_view_purchase_time_distribution(): void
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

    public function test_transactions_are_grouped_into_purchase_time_ranges(): void
    {
        $this->seedPurchaseTimeData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.filter.mode', 'date_range')
            ->assertJsonPath('data.filter.date_from', '2026-09-01')
            ->assertJsonPath('data.filter.date_to', '2026-09-30')
            ->assertJsonPath('data.filter.platforms', [])
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.totals', ['transactions' => 9])
            ->assertJsonPath('data.time_ranges', [
                ['key' => 'dawn', 'label' => 'Dini Hari', 'start' => '00:00', 'end' => '05:59', 'transactions' => 2, 'percentage' => 22.2],
                ['key' => 'morning', 'label' => 'Pagi', 'start' => '06:00', 'end' => '10:59', 'transactions' => 2, 'percentage' => 22.2],
                ['key' => 'midday', 'label' => 'Siang', 'start' => '11:00', 'end' => '14:59', 'transactions' => 1, 'percentage' => 11.1],
                ['key' => 'afternoon', 'label' => 'Sore', 'start' => '15:00', 'end' => '17:59', 'transactions' => 1, 'percentage' => 11.1],
                ['key' => 'night', 'label' => 'Malam', 'start' => '18:00', 'end' => '23:59', 'transactions' => 2, 'percentage' => 22.2],
                ['key' => 'unknown', 'label' => 'Tidak diketahui', 'start' => null, 'end' => null, 'transactions' => 1, 'percentage' => 11.1],
            ])
            ->assertJsonPath('data.labels', [
                'Dini Hari (00:00 - 05:59)',
                'Pagi (06:00 - 10:59)',
                'Siang (11:00 - 14:59)',
                'Sore (15:00 - 17:59)',
                'Malam (18:00 - 23:59)',
                'Tidak diketahui',
            ])
            ->assertJsonPath('data.datasets', [['label' => 'Transaksi', 'data' => [2, 2, 1, 1, 2, 1]]]);
    }

    public function test_platform_filter_only_counts_the_selected_platform(): void
    {
        $this->seedPurchaseTimeData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&platform=app-b')
            ->assertOk()
            ->assertJsonPath('data.filter.platform', 'app-b')
            ->assertJsonPath('data.totals.transactions', 2)
            ->assertJsonCount(5, 'data.time_ranges')
            ->assertJsonPath('data.datasets.0.data', [1, 0, 0, 0, 1])
            ->assertJsonPath('data.time_ranges.0.percentage', 50)
            ->assertJsonPath('data.time_ranges.1.percentage', 0);
    }

    public function test_distribution_matches_the_performance_summary_total_transactions(): void
    {
        $this->seedPurchaseTimeData();

        $summary = $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?date_from=2026-09-01&date_to=2026-09-30')
            ->json('data.kpis.total_transactions.value');

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertJsonPath('data.totals.transactions', $summary);
    }

    public function test_period_without_transactions_returns_empty_state(): void
    {
        $this->seedPurchaseTimeData();

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.message', 'Data tidak tersedia untuk filter yang dipilih.')
            ->assertJsonPath('data.totals', ['transactions' => 0])
            ->assertJsonCount(5, 'data.time_ranges')
            ->assertJsonPath('data.datasets.0.data', [0, 0, 0, 0, 0])
            ->assertJsonPath('data.time_ranges.0.percentage', 0);
    }
}
