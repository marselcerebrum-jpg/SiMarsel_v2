<?php

namespace App\Repositories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Task;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TaskRepository
{
    /**
     * Monitoring metric per status, in workflow order.
     */
    public const STATUS_METRICS = [
        'backlog' => TaskStatus::Backlog,
        'todo' => TaskStatus::Todo,
        'on_process' => TaskStatus::InProgress,
        'ready_review' => TaskStatus::ReadyReview,
        'on_review' => TaskStatus::OnReview,
        'completed' => TaskStatus::Done,
    ];

    /**
     * Tasks the account may see, narrowed by the requested filters. The visibility scope is always
     * applied, so a filter can never widen what the account is allowed to see.
     *
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string}  $filters
     * @return Builder<Task>
     */
    public function visible(Account $account, array $filters): Builder
    {
        return $this->filter(Task::query()->visibleTo($account), $filters);
    }

    /**
     * Tasks for monitoring. The "tasklist" scope is the account's own Tasklist visibility, so its numbers match
     * the Board; the "report" scope covers every division, which every role may read but not edit.
     *
     * The period is matched on the column named by date_basis (created_at or deadline). On the deadline basis a
     * task without a deadline falls outside any period; on the created_at basis every task has a date.
     *
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string, date_basis: string, date_from: ?string, date_to: ?string}  $filters
     * @return Builder<Task>
     */
    public function monitored(Account $account, string $scope, array $filters): Builder
    {
        $query = Task::query()->when($scope === 'tasklist', fn (Builder $query) => $query->visibleTo($account));
        $column = $filters['date_basis'] === 'deadline' ? 'deadline' : 'created_at';

        return $this->filter($query, $filters)
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->whereDate($column, '>=', $date))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query->whereDate($column, '<=', $date));
    }

    /**
     * Monitoring counts in one query. An active task is any task not yet Done; tasks without a deadline are
     * never Overdue or Due Today. Deadlines are compared as a range so a stored time part cannot hide a match.
     *
     * @param  Builder<Task>  $query
     * @return array<string, int>
     */
    public function monitoringCounts(Builder $query, CarbonInterface $today): array
    {
        $done = TaskStatus::Done->value;
        $start = $today->toDateString();
        $end = $today->copy()->addDay()->toDateString();

        $select = (clone $query)->toBase()->selectRaw('count(*) as total_tasks');

        foreach (self::STATUS_METRICS as $metric => $status) {
            $select->selectRaw("coalesce(sum(case when status = ? then 1 else 0 end), 0) as {$metric}", [$status->value]);
        }

        $row = $select
            ->selectRaw('coalesce(sum(case when status <> ? then 1 else 0 end), 0) as unfinished', [$done])
            ->selectRaw('coalesce(sum(case when status <> ? and deadline < ? then 1 else 0 end), 0) as overdue', [$done, $start])
            ->selectRaw('coalesce(sum(case when status <> ? and deadline >= ? and deadline < ? then 1 else 0 end), 0) as due_today', [$done, $start, $end])
            ->selectRaw('coalesce(sum(case when status <> ? and priority = ? then 1 else 0 end), 0) as urgent', [$done, TaskPriority::Urgent->value])
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Total, completed, unfinished, and overdue tasks per division, keyed by division id.
     *
     * @param  Builder<Task>  $query
     * @return array<int, array{total: int, completed: int, unfinished: int, overdue: int}>
     */
    public function monitoringCountsByDivision(Builder $query, CarbonInterface $today): array
    {
        $done = TaskStatus::Done->value;

        return (clone $query)->toBase()
            ->select('division_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completed', [$done])
            ->selectRaw('coalesce(sum(case when status <> ? then 1 else 0 end), 0) as unfinished', [$done])
            ->selectRaw('coalesce(sum(case when status <> ? and deadline < ? then 1 else 0 end), 0) as overdue', [$done, $today->toDateString()])
            ->groupBy('division_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->division_id => [
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'unfinished' => (int) $row->unfinished,
                'overdue' => (int) $row->overdue,
            ]])
            ->all();
    }

    /**
     * Total and active tasks per priority, with every priority present.
     *
     * @param  Builder<Task>  $query
     * @return array<string, array{total: int, unfinished: int}>
     */
    public function monitoringCountsByPriority(Builder $query): array
    {
        $counts = (clone $query)->toBase()
            ->select('priority')
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when status <> ? then 1 else 0 end), 0) as unfinished', [TaskStatus::Done->value])
            ->groupBy('priority')
            ->get()
            ->keyBy('priority');

        return collect(TaskPriority::cases())
            ->mapWithKeys(fn (TaskPriority $priority) => [$priority->value => [
                'total' => (int) ($counts[$priority->value]->total ?? 0),
                'unfinished' => (int) ($counts[$priority->value]->unfinished ?? 0),
            ]])
            ->all();
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string}  $filters
     * @return Builder<Task>
     */
    private function filter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['division'], fn (Builder $query, string $division) => $query
                ->whereHas('division', fn (Builder $query) => $query->where('code_division', $division)))
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('title', 'like', "%{$search}%"))
            ->when($filters['priority'], fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status));
    }

    /**
     * Tasks of every division whose dates fall in the period, for the shared schedule. Not scoped to the
     * account: the schedule shows other divisions' tasks, and what an item reveals is decided per item.
     * A task with only a start date or only a deadline counts as that one day; one without either is left out.
     *
     * @return Collection<int, Task>
     */
    public function scheduled(CarbonInterface $from, CarbonInterface $to, ?string $division): Collection
    {
        return Task::query()
            ->with('division')
            ->whereRaw('coalesce(start_date, deadline) <= ?', [$to->copy()->endOfDay()->toDateTimeString()])
            ->whereRaw('coalesce(deadline, start_date) >= ?', [$from->copy()->startOfDay()->toDateTimeString()])
            ->when($division, fn (Builder $query, string $division) => $query
                ->whereHas('division', fn (Builder $query) => $query->where('code_division', $division)))
            ->orderByRaw('coalesce(start_date, deadline)')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks the account may see with a deadline in the period, for the Calendar's deadline list. Unlike the
     * shared schedule this stays inside the account's visibility scope; tasks without a deadline are left out.
     *
     * @return Collection<int, Task>
     */
    public function deadlinesBetween(Account $account, CarbonInterface $from, CarbonInterface $to, ?string $division): Collection
    {
        return $this->visible($account, ['search' => null, 'division' => $division, 'priority' => null, 'status' => null])
            ->with(['division', 'pic'])
            ->whereBetween('deadline', [$from->copy()->startOfDay()->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()])
            ->orderBy('deadline')
            ->orderBy('id')
            ->get();
    }

    /**
     * Number of tasks per status, with every status present so empty Board columns show 0.
     *
     * @param  Builder<Task>  $query
     * @return array<string, int>
     */
    public function countByStatus(Builder $query): array
    {
        $counts = (clone $query)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(TaskStatus::cases())
            ->mapWithKeys(fn (TaskStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function sort(Builder $query, string $sort, string $direction): Builder
    {
        match ($sort) {
            'priority' => $this->orderByCase($query, 'priority', TaskPriority::cases(), $direction),
            'status' => $this->orderByCase($query, 'status', TaskStatus::cases(), $direction),
            'division' => $query->orderBy(
                Division::select('name')->whereColumn('divisions.id', 'tasks.division_id'),
                $direction,
            ),
            'pic' => $query->orderBy(
                Account::select('fullname')->whereColumn('accounts.id', 'tasks.pic_id'),
                $direction,
            ),
            // Tasks without a date always come last, whichever the direction.
            'start_date', 'deadline' => $query->orderByRaw("{$sort} is null")->orderBy($sort, $direction),
            default => $query->orderBy($sort, $direction),
        };

        return $query->orderBy('id', $direction);
    }

    /**
     * Order by the enum's declared order (workflow order for status, severity for priority)
     * instead of alphabetically.
     *
     * @param  Builder<Task>  $query
     * @param  array<int, BackedEnum>  $cases
     */
    private function orderByCase(Builder $query, string $column, array $cases, string $direction): void
    {
        $whens = implode(' ', array_fill(0, count($cases), 'when ? then ?'));
        $bindings = collect($cases)->flatMap(fn (BackedEnum $case, int $rank) => [$case->value, $rank])->all();

        $query->orderByRaw("case {$column} {$whens} end {$direction}", $bindings);
    }
}
