<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TaskMonitoringService
{
    /**
     * Aggregate the cross-division reporting scope. This is read-only and deliberately does not
     * reuse the narrower Tasklist visibility scope for managers and staff.
     *
     * @param array{division: ?string, status: ?string, priority: ?string, date_from: ?string, date_to: ?string} $filters
     * @return array<string, mixed>
     */
    public function summarize(array $filters): array
    {
        $today = CarbonImmutable::today();
        $active = "status <> '".TaskStatus::Done->value."'";
        $query = Task::query()
            ->when($filters['division'], fn (Builder $query, string $division) => $query
                ->whereHas('division', fn (Builder $query) => $query->where('code_division', $division)))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'], fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->where('deadline', '>=', $date))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query->where('deadline', '<=', $date));

        $counts = (clone $query)->toBase()
            ->selectRaw('count(*) as total_tasks')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as backlog', [TaskStatus::Backlog->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as todo', [TaskStatus::Todo->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as on_process', [TaskStatus::InProgress->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as ready_for_qa', [TaskStatus::ReadyForQa->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as on_qa', [TaskStatus::OnQa->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completed', [TaskStatus::Done->value])
            ->selectRaw('coalesce(sum(case when status <> ? then 1 else 0 end), 0) as unfinished', [TaskStatus::Done->value])
            ->selectRaw("coalesce(sum(case when {$active} and deadline < ? then 1 else 0 end), 0) as overdue", [$today->toDateString()])
            ->selectRaw("coalesce(sum(case when {$active} and deadline = ? then 1 else 0 end), 0) as due_today", [$today->toDateString()])
            ->selectRaw('coalesce(sum(case when status <> ? and priority = ? then 1 else 0 end), 0) as urgent', [TaskStatus::Done->value, TaskPriority::Urgent->value])
            ->first();

        $total = (int) $counts->total_tasks;
        $completed = (int) $counts->completed;

        return [
            'scope' => 'all_divisions',
            'as_of' => $today->toDateString(),
            'filters' => $filters,
            'metrics' => [
                'total_tasks' => $total,
                'backlog' => (int) $counts->backlog,
                'todo' => (int) $counts->todo,
                'on_process' => (int) $counts->on_process,
                'ready_for_qa' => (int) $counts->ready_for_qa,
                'on_qa' => (int) $counts->on_qa,
                'completed' => $completed,
                'unfinished' => (int) $counts->unfinished,
                'overdue' => (int) $counts->overdue,
                'due_today' => (int) $counts->due_today,
                'urgent' => (int) $counts->urgent,
                'completion_rate' => $total === 0 ? 0 : round(($completed / $total) * 100, 2),
            ],
        ];
    }
}
