<?php

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Shaped like the Markaz platforms: an uppercase name (JADIASN) with a lowercase slug (jadiasn).
 *
 * @extends Factory<Platform>
 */
class PlatformFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'JADI'.strtoupper(fake()->unique()->lexify('??????')),
            'slug' => fn (array $attributes) => strtolower($attributes['name']),
        ];
    }
}
