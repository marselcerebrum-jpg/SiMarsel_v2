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
     * Tasks the account may see, narrowed by the requested filters. The visibility scope is always
     * applied, so a filter can never widen what the account is allowed to see.
     *
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string}  $filters
     * @return Builder<Task>
     */
    public function visible(Account $account, array $filters): Builder
    {
        return Task::query()
            ->visibleTo($account)
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
