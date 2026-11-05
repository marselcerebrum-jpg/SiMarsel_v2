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
     * Admin creates tasks for every division and a Manager for the division they manage; Staff cannot create tasks.
     */
    public function create(Account $user): bool
    {
        return $user->isAdmin() || ($user->isManager() && $user->division_id !== null);
    }

    /**
     * Admin and the Manager of its division may edit the task; its PIC may only update its status.
     */
    public function update(Account $user, Task $task): bool
    {
        return $task->updatableBy($user);
    }
}
