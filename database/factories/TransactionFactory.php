<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'markaz_user_id' => fake()->numberBetween(1, 100000),
            'trx_id' => fake()->unique()->uuid(),
            'paid_date' => fake()->date(),
            'paid_time' => fake()->time(),
            'payment_method' => fake()->randomElement(['bank_transfer', 'e_wallet', 'credit_card']),
            'nominal' => fake()->randomFloat(2, 10000, 1000000),
            'promo_code' => null,
            'product' => fake()->words(3, true),
            'product_type' => fake()->randomElement([Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL]),
            'customer_email' => fake()->safeEmail(),
            'status_message' => Transaction::STATUS_SUCCESS,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status_message' => 'failed']);
    }
}
