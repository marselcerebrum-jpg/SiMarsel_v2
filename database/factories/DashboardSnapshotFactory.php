<?php

namespace Database\Factories;

use App\Enums\DashboardDataset;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A complete transactions snapshot of 2026-11-15 at 14.00 WIB.
 *
 * @extends Factory<DashboardSnapshot>
 */
class DashboardSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'dataset' => DashboardDataset::Transactions,
            'date_from' => '2026-11-15',
            'date_to' => '2026-11-15',
            'cutoff_at' => '2026-11-15 14:00:00',
            'is_complete' => true,
            'payload' => ['total_checkouts' => 0, 'purchase_attempts' => 0, 'success_purchases' => 0],
            'recorded_at' => '2026-11-15 14:07:00',
        ];
    }

    public function partial(): static
    {
        return $this->state(['is_complete' => false]);
    }

    /**
     * @param  array<string, int>  $totals  Cumulative downloader total per covered date.
     */
    public function downloaders(array $totals): static
    {
        return $this->state([
            'dataset' => DashboardDataset::Downloaders,
            'payload' => ['total' => array_sum($totals), 'daily' => $totals],
        ]);
    }
}
