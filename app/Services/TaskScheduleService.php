<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ScheduleEvent;
use App\Models\Task;
use App\Repositories\TaskRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskScheduleService
{
    public function __construct(private TaskRepository $tasks) {}

    /**
     * The shared schedule of a period for the Timeline: the tasks of every division and the agendas the account
     * may see, ordered by the day they start. Seeing an item on the schedule is not seeing its details; whether
     * the account may open them is decided per item by the task's own access rule.
     *
     * Focusing on a division keeps its tasks and agendas, plus the agendas of every division.
     *
     * @return Collection<int, Task|ScheduleEvent>
     */
    public function schedule(Account $account, CarbonInterface $from, CarbonInterface $to, ?string $division): Collection
    {
        $events = ScheduleEvent::query()
            ->with(['division', 'task'])
            ->visibleTo($account)
            ->within($from, $to)
            ->when($division, fn (Builder $query, string $division) => $query->where(fn (Builder $query) => $query
                ->whereNull('division_id')
                ->orWhereHas('division', fn (Builder $query) => $query->where('code_division', $division))))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return $this->tasks->scheduled($from, $to, $division)
            ->toBase()
            ->merge($events)
            ->sortBy(fn (Task|ScheduleEvent $item) => [$this->startOf($item), $item instanceof Task ? 0 : 1, $item->id])
            ->values();
    }

    private function startOf(Task|ScheduleEvent $item): string
    {
        return $item instanceof Task
            ? ($item->start_date ?? $item->deadline)->toDateString()
            : $item->start_date->toDateString();
    }
}
