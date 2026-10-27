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
     * Seeing a task does not grant editing it: only Admin, or the Manager of its division, may edit.
     */
    public function update(Account $user, Task $task): bool
    {
        return $task->managedBy($user);
    }

    /**
     * Only Admin, or the Manager of the task's division, may assign, reassign, or forward its PIC; Staff never.
     */
    public function assign(Account $user, Task $task): bool
    {
        return $task->managedBy($user);
    }

    /**
     * Anyone who sees the task may request a status change; whether this account may make this particular
     * move (PIC vs. Admin/Manager) is checked by TaskService so the rejection carries its reason.
     */
    public function changeStatus(Account $user, Task $task): bool
    {
        return $task->isVisibleTo($user);
    }
}
