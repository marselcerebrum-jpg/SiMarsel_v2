<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\ScheduleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleEvent>
 */
class ScheduleEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 weeks', '+2 weeks');

        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'start_date' => $startDate,
            'end_date' => fake()->optional()->dateTimeBetween($startDate, (clone $startDate)->modify('+5 days')),
            'is_shared' => true,
        ];
    }

    /**
     * An internal agenda meant for the given divisions; none leaves it visible to Admin only.
     *
     * @param  array<int, Division>  $divisions
     */
    public function internalFor(array $divisions): static
    {
        return $this->state(['is_shared' => false])
            ->afterCreating(fn (ScheduleEvent $event) => $event->targetDivisions()->attach(
                array_map(fn (Division $division) => $division->id, $divisions),
            ));
    }
}
