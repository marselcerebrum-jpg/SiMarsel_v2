<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Database\Eloquent\Collection;

class TaskActivityService
{
    /**
     * Every history entry of the task, newest first, with who did it. Entries stay after the task changes
     * status or PIC, since each one is only ever appended.
     *
     * @return Collection<int, TaskActivity>
     */
    public function list(Task $task): Collection
    {
        return $task->activities()->with('actor')->get();
    }
}
