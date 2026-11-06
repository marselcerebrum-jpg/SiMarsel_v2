<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Division;
use App\Repositories\TaskRepository;
use Carbon\CarbonImmutable;

class TaskMonitoringService
{
    public const SCOPE_REPORT = 'report';

    public const SCOPE_TASKLIST = 'tasklist';

    public function __construct(private TaskRepository $tasks) {}

    /**
     * Monitoring KPIs, completion rate, and per-division and per-priority breakdowns under the given scope and
     * filters. Read-only: seeing a task here grants no right to edit it.
     *
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array<string, mixed>
     */
    public function summarize(Account $account, string $scope, array $filters): array
    {
        $today = CarbonImmutable::today();
        $query = $this->tasks->monitored($account, $scope, $filters);
        $counts = $this->tasks->monitoringCounts($query, $today);

        return [
            'scope' => $this->scope($account, $scope),
            'as_of' => $today->toDateString(),
            'filters' => $filters,
            'metrics' => [
                ...$counts,
                'completion_rate' => $this->completionRate($counts['completed'], $counts['total_tasks']),
            ],
            'by_priority' => $this->tasks->monitoringCountsByPriority($query),
            'by_division' => $this->byDivision($this->tasks->monitoringCountsByDivision($query, $today)),
        ];
    }

    /**
     * Completed / Total × 100, rounded to two decimals; 0 when there are no tasks.
     */
    public function completionRate(int $completed, int $total): float
    {
        return $total === 0 ? 0.0 : round($completed / $total * 100, 2);
    }

    /**
     * @return array{key: string, label: string}
     */
    private function scope(Account $account, string $scope): array
    {
        if ($scope === self::SCOPE_REPORT || $account->isAdmin()) {
            return ['key' => $scope, 'label' => 'Seluruh divisi'];
        }

        return [
            'key' => $scope,
            'label' => $account->isManager() ? 'Tugas divisi Anda' : 'Tugas yang ditugaskan kepada Anda',
        ];
    }

    /**
     * @param  array<int, array{total: int, completed: int, unfinished: int, overdue: int}>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function byDivision(array $counts): array
    {
        return Division::query()
            ->whereKey(array_keys($counts))
            ->orderBy('name')
            ->get(['id', 'code_division', 'name'])
            ->map(fn (Division $division) => [
                'code_division' => $division->code_division,
                'name' => $division->name,
                ...$counts[$division->id],
                'completion_rate' => $this->completionRate($counts[$division->id]['completed'], $counts[$division->id]['total']),
            ])
            ->all();
    }
}
