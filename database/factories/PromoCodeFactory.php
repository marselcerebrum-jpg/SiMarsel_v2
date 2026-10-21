<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Only the master row; create promo codes through PromoCodeService to also get their metadata version.
 *
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
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
            'code' => fake()->unique()->bothify('PROMO####'),
            'funnel_id' => null,
            'source_id' => null,
        ];
    }
}
