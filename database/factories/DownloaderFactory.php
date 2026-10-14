<?php

namespace Database\Factories;

use App\Models\Downloader;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
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
            'date' => fake()->date(),
            'total' => fake()->numberBetween(0, 500),
        ];
    }
}
