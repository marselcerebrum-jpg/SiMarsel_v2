<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\DuplicateTaskSubmissionException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Platform;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Repositories\TaskRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TaskService
{
    /**
     * How long an identical submission from the same account is treated as a resend.
     */
    public const DUPLICATE_WINDOW_SECONDS = 10;

    public function __construct(private TaskRepository $tasks) {}

    /**
     * Tasks for the Board and Table views, plus per-status counts under the same filters.
     *
     * @param  array{search: ?string, division: ?string, priority: ?string, status: ?string}  $filters
     * @return array{tasks: Collection<int, Task>|LengthAwarePaginator, status_counts: array<string, int>}
     */
    public function list(Account $account, array $filters, string $sort, string $direction, ?int $perPage): array
    {
        $query = $this->tasks->visible($account, $filters);
        $statusCounts = $this->tasks->countByStatus($query);

        $query = $this->tasks->sort($query, $sort, $direction)->with(['division', 'pic']);

        return [
            'tasks' => $perPage ? $query->paginate($perPage)->withQueryString() : $query->get(),
            'status_counts' => $statusCounts,
        ];
    }

    public function detail(Task $task): Task
    {
        return $task->load(['category', 'division', 'platform', 'pic', 'creator']);
    }

    /**
     * Create a task from validated input. The creator is the logged-in account, the status starts at
     * Back Log unless To Do is chosen, and QA is required whenever the category requires it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicateTaskSubmissionException When the same account sent the same task moments ago.
     */
    public function create(Account $creator, array $data): Task
    {
        $fingerprint = 'task-create:'.$creator->id.':'.sha1(json_encode($data));

        if (! Cache::add($fingerprint, true, self::DUPLICATE_WINDOW_SECONDS)) {
            throw new DuplicateTaskSubmissionException;
        }

        try {
            $task = Task::create([
                ...$data,
                'creator_id' => $creator->id,
                'priority' => $data['priority'] ?? TaskPriority::Medium->value,
                'status' => $data['status'] ?? TaskStatus::Backlog->value,
                'requires_review' => $this->requiresReview($data),
            ]);
        } catch (Throwable $e) {
            Cache::forget($fingerprint);

            throw $e;
        }

        return $this->detail($task);
    }

    /**
     * Options for the Create Task form: the divisions the account may create tasks for with each
     * division's assignable PICs, task categories with their QA configuration, platforms, and enums.
     *
     * @return array<string, mixed>
     */
    public function createOptions(Account $account): array
    {
        return [
            'divisions' => Division::query()
                ->taskCreatableBy($account)
                ->orderBy('name')
                ->get(['id', 'code_division', 'name'])
                ->map(fn (Division $division) => [
                    ...$division->only(['id', 'code_division', 'name']),
                    'pics' => Account::query()
                        ->assignableIn($division->id)
                        ->orderBy('fullname')
                        ->get(['id', 'fullname'])
                        ->map(fn (Account $pic) => $pic->only(['id', 'fullname']))
                        ->all(),
                ])
                ->all(),
            'categories' => TaskCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'requires_review'])
                ->map(fn (TaskCategory $category) => $category->only(['id', 'name', 'requires_review']))
                ->all(),
            'platforms' => Platform::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Platform $platform) => $platform->only(['id', 'name']))
                ->all(),
            'priorities' => collect(TaskPriority::cases())
                ->map(fn (TaskPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()])
                ->all(),
            'statuses' => collect(TaskStatus::initial())
                ->map(fn (TaskStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->all(),
            'defaults' => [
                'priority' => TaskPriority::Medium->value,
                'status' => TaskStatus::Backlog->value,
            ],
        ];
    }

    /**
     * A category that requires QA always does; otherwise QA is required only when the creator asks for it.
     *
     * @param  array<string, mixed>  $data
     */
    private function requiresReview(array $data): bool
    {
        $categoryRequiresReview = isset($data['category_id'])
            && TaskCategory::whereKey($data['category_id'])->value('requires_review');

        return $categoryRequiresReview || (bool) ($data['requires_review'] ?? false);
    }

    /**
     * Role, division scope, and filter options the Tasklist page needs for the logged-in account.
     *
     * @return array<string, mixed>
     */
    public function meta(Account $account): array
    {
        $account->loadMissing('division');

        return [
            'role' => match (true) {
                $account->isAdmin() => 'admin',
                $account->isManager() => 'manager',
                default => 'staff',
            },
            'division' => $account->division?->code_division,
            // Admin starts on "Semua Divisi" (null); a Manager starts on the division they manage.
            'default_division' => $account->isManager() ? $account->division?->code_division : null,
            'divisions' => $this->divisionOptions($account),
            'statuses' => collect(TaskStatus::cases())
                ->map(fn (TaskStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->all(),
            'priorities' => collect(TaskPriority::cases())
                ->map(fn (TaskPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()])
                ->all(),
        ];
    }

    /**
     * Divisions the account may filter by: every division for Admin, otherwise only the division
     * a Manager manages and divisions the account has visible tasks in.
     *
     * @return array<int, array{code_division: string, name: string}>
     */
    private function divisionOptions(Account $account): array
    {
        return Division::query()
            ->unless($account->isAdmin(), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('id', Task::query()->visibleTo($account)->select('division_id'))
                ->when($account->isManager() && $account->division_id, fn (Builder $query) => $query
                    ->orWhere('id', $account->division_id))))
            ->orderBy('name')
            ->get(['code_division', 'name'])
            ->map(fn (Division $division) => $division->only(['code_division', 'name']))
            ->all();
    }
}
