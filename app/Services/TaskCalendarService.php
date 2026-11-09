<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ScheduleEvent;
use App\Models\Task;
use App\Repositories\TaskRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class TaskCalendarService
{
    public function __construct(private TaskRepository $tasks) {}

    /**
     * The Calendar of a month, as two separate lists: the agendas the account may see, shared across divisions,
     * and the deadlines of the tasks the account may see (Admin every division, a Manager their division,
     * Staff their own tasks). A division filter narrows both lists and never widens what the account may see.
     *
     * @return array{events: Collection<int, ScheduleEvent>, deadlines: Collection<int, Task>}
     */
    public function calendar(Account $account, CarbonInterface $month, ?string $division): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        return [
            'events' => ScheduleEvent::query()
                ->with(['ownerDivision', 'targetDivisions', 'task'])
                ->visibleTo($account)
                ->within($from, $to)
                ->forDivision($division)
                ->orderBy('start_date')
                ->orderBy('id')
                ->get(),
            'deadlines' => $this->tasks->deadlinesBetween($account, $from, $to, $division),
        ];
    }
}
