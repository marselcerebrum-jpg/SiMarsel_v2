<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Division;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->optional()->dateTimeBetween('-1 month', '+1 week');

        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'division_id' => fn () => Division::create([
                'code_division' => fake()->unique()->bothify('DIV-###??'),
                'name' => fake()->unique()->lexify('Division ??????'),
            ])->id,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'status' => TaskStatus::Backlog,
            'start_date' => $startDate,
            'deadline' => $startDate ? fake()->dateTimeBetween($startDate, '+1 month') : null,
            'requires_review' => fake()->boolean(),
            'evidence_required' => fake()->boolean(),
        ];
    }
}
