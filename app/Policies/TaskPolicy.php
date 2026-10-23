<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Task;

class TaskPolicy
{
    /**
     * Every logged-in role can open the task list; its rows are scoped by Task::scopeVisibleTo().
     */
    public function viewAny(Account $user): bool
    {
        return true;
    }

    /**
     * Admin sees every task, a Manager the tasks of their division, Staff only tasks they are PIC of.
     */
    public function view(Account $user, Task $task): bool
    {
        return $task->isVisibleTo($user);
    }

    /**
     * Seeing a task does not grant editing it: only Admin, or the Manager of its division, may edit.
     */
    public function update(Account $user, Task $task): bool
    {
        return $task->managedBy($user);
    }
}
