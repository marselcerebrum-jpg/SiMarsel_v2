<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Copies the legacy tasks.pic_id into task_assignments and reports how the two compare.
 *
 * Only tasks with a pic_id and no assignment row at all are backfilled. A task that already has assignment history is
 * left alone, so running the backfill again never adds a duplicate and never brings back a PIC who was released after
 * the switch to task_assignments (pic_id is no longer updated by the application).
 */
class TaskAssignmentBackfillService
{
    /**
     * @return array{tasks_with_pic: int, matched: int, missing: int, diverged: int, active_assignments: int}
     */
    public function report(): array
    {
        $withPic = Task::withTrashed()->whereNotNull('pic_id');

        $matched = (clone $withPic)->whereHas('activeAssignments', fn (Builder $query) => $query->whereColumn('task_assignments.account_id', 'tasks.pic_id'))->count();
        $missing = (clone $withPic)->whereDoesntHave('assignments')->count();

        return [
            'tasks_with_pic' => (clone $withPic)->count(),
            // pic_id has an active assignment.
            'matched' => $matched,
            // pic_id has no assignment history at all: the backfill would add it.
            'missing' => $missing,
            // The task has assignment history but pic_id is not an active PIC: changed through the multi-PIC API.
            'diverged' => (clone $withPic)->count() - $matched - $missing,
            'active_assignments' => TaskAssignment::query()->active()->count(),
        ];
    }

    /**
     * Backfill the missing assignments. Who assigned the PIC and when come from the latest assigned/reassigned history
     * entry, or the task's creator and creation time when there is none.
     *
     * @return int The number of assignments created.
     */
    public function apply(): int
    {
        $created = 0;

        Task::withTrashed()
            ->whereNotNull('pic_id')
            ->whereDoesntHave('assignments')
            ->select(['id', 'pic_id', 'creator_id', 'created_at'])
            ->chunkById(500, function ($tasks) use (&$created) {
                // Ordered oldest first, so keyBy() keeps the latest entry of each task.
                $latest = TaskActivity::query()
                    ->whereIn('task_id', $tasks->pluck('id'))
                    ->whereIn('action', [TaskActivity::ASSIGNED, TaskActivity::REASSIGNED])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['task_id', 'account_id', 'created_at'])
                    ->keyBy('task_id');

                $rows = $tasks->map(fn (Task $task) => [
                    'task_id' => $task->id,
                    'account_id' => $task->pic_id,
                    'assigned_by' => $latest->has($task->id) ? $latest[$task->id]->account_id : $task->creator_id,
                    'assigned_at' => $latest->get($task->id)?->created_at ?? $task->created_at ?? now(),
                ]);

                $created += DB::table('task_assignments')->insertOrIgnore($rows->all());
            });

        return $created;
    }
}
