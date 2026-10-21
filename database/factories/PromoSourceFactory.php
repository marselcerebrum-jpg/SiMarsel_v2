<?php

namespace Database\Factories;

use App\Models\PromoSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoSource>
 */
class PromoSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Instagram', 'TikTok', 'YouTube', 'Reseller', 'Website', 'WhatsApp']).' '.fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
