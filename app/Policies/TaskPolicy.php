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

    /**
     * Only Admin, or the Manager of the task's division, may approve a task that requires QA or request its
     * revision; Staff only sees the result. Whether the task is in On Review is checked by TaskReviewService so the
     * rejection carries its reason.
     */
    public function review(Account $user, Task $task): bool
    {
        return $task->reviewableBy($user);
    }

    /**
     * Only the task's PIC sends evidence for it; Admin and Managers see it through view(). Whether the task's
     * status still accepts evidence is checked by TaskEvidenceService so the rejection carries its reason.
     */
    public function submitEvidence(Account $user, Task $task): bool
    {
        return $task->assignedTo($user);
    }
}
