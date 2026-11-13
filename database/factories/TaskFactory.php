<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
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

    /**
     * A task created with the legacy pic_id gets the active assignment the backfill would give it, so it looks like
     * migrated data.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Task $task) {
            if ($task->pic_id !== null && ! $task->assignments()->exists()) {
                $task->assignments()->create([
                    'account_id' => $task->pic_id,
                    'assigned_by' => $task->creator_id,
                    'assigned_at' => $task->created_at ?? now(),
                ]);
            }
        });
    }

    /**
     * Active PICs for the task.
     */
    public function withPics(Account ...$pics): static
    {
        return $this->afterCreating(function (Task $task) use ($pics) {
            foreach ($pics as $pic) {
                $task->assignments()->create(['account_id' => $pic->id, 'assigned_by' => $task->creator_id, 'assigned_at' => now()]);
            }
        });
    }
}
