<?php

namespace Database\Factories;

use App\Models\Downloader;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Daily app downloads per platform.
 *
 * @extends Factory<Downloader>
 */
class DownloaderFactory extends Factory
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
            'date' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'total' => fake()->numberBetween(40, 400),
        ];
    }
}
