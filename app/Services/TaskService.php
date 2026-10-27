<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\DuplicateTaskSubmissionException;
use App\Exceptions\TaskVersionConflictException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Platform;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskCategory;
use App\Repositories\TaskRepository;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        return $task->load(['category', 'division', 'platform', 'pic', 'creator', 'activities.actor']);
    }

    /**
     * Create a task from validated input. The creator is the logged-in account, the status starts at
     * Back Log unless To Do is chosen, and QA is required whenever the category requires it.
     *
     * @param  array<string, mixed>  $data
     *
     * The duplicate guard is an atomic cache lock that is kept for the duplicate window, so of two identical
     * requests arriving at the same time only one acquires it. The cache store must support atomic locks
     * (database, redis, memcached) and be shared by every app server; the default database store is.
     *
     * @throws DuplicateTaskSubmissionException When the same account sent the same task moments ago.
     */
    public function create(Account $creator, array $data): Task
    {
        $attributes = [
            ...$data,
            'creator_id' => $creator->id,
            'priority' => $data['priority'] ?? TaskPriority::Medium->value,
            'status' => $data['status'] ?? TaskStatus::Backlog->value,
            'requires_review' => $this->requiresReview($data),
        ];

        $lock = Cache::lock($this->submissionFingerprint($attributes), self::DUPLICATE_WINDOW_SECONDS);

        if (! $lock->get()) {
            throw new DuplicateTaskSubmissionException;
        }

        try {
            $task = DB::transaction(function () use ($creator, $attributes) {
                $task = Task::create($attributes);

                $task->activities()->create(['account_id' => $creator->id, 'action' => TaskActivity::CREATED]);

                return $task;
            });
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        return $this->detail($task);
    }

    /**
     * Apply an edit from an Admin or the division's Manager. Only fields whose value actually changes are
     * checked against the task's current condition, so a form that resends unchanged values still saves.
     *
     * The edit must carry the version it was based on; the row is locked while that is compared, so of two
     * edits based on the same version only the first is saved and the second gets a conflict instead of
     * silently overwriting it.
     *
     * @param  array<string, mixed>  $data  Validated input including version.
     *
     * @throws TaskVersionConflictException When the task was saved by someone else after the editor loaded it.
     * @throws ValidationException When a change breaks the field, workflow, assignment, or QA rules.
     */
    public function update(Account $editor, Task $task, array $data): Task
    {
        $baseVersion = (int) $data['version'];
        unset($data['version']);

        DB::transaction(function () use ($editor, $task, $data, $baseVersion) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $baseVersion) {
                throw new TaskVersionConflictException($locked->version);
            }

            $lockedFields = $locked->lockedFieldsFor($editor);
            $statusDenial = $this->statusDenial($editor, $locked, $data['status'] ?? null);

            $locked->fill($data);
            $this->applyCategoryReview($locked, $data);

            if (! $locked->isDirty()) {
                return;
            }

            $this->guardChanges($locked, $statusDenial, $lockedFields);

            if ($locked->isDirty('status')) {
                $locked->completed_at = $locked->status === TaskStatus::Done ? now() : null;
            }

            $changes = $this->describeChanges($locked);

            $locked->version = $baseVersion + 1;
            $locked->save();

            $locked->activities()->create([
                'account_id' => $editor->id,
                'action' => TaskActivity::UPDATED,
                'changes' => $changes,
            ]);
        });

        return $this->detail($task->refresh());
    }

    /**
     * Move the task to another status following the transition matrix: the PIC starts and submits the work
     * (To Do → On Process → Ready for QA, or straight to Done when QA is not required), and Admin or the
     * division's Manager moves Back Log → To Do and runs the review (Ready for QA → On QA → Done, or back to
     * On Process for revision). The change is appended to the history.
     *
     * @param  array{version: int|string, status: string}  $data
     *
     * @throws TaskVersionConflictException When the task was saved by someone else after the actor loaded it.
     * @throws ValidationException When the move is not in the matrix, belongs to another role, or the task has no PIC.
     */
    public function changeStatus(Account $actor, Task $task, array $data): Task
    {
        $baseVersion = (int) $data['version'];

        DB::transaction(function () use ($actor, $task, $data, $baseVersion) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $baseVersion) {
                throw new TaskVersionConflictException($locked->version);
            }

            $status = TaskStatus::from($data['status']);

            if ($locked->status === $status) {
                throw ValidationException::withMessages(['status' => "Tugas sudah berstatus {$status->label()}."]);
            }

            if ($denial = $locked->statusChangeDenialFor($actor, $status)) {
                throw ValidationException::withMessages(['status' => $denial]);
            }

            if ($locked->pic_id === null) {
                throw ValidationException::withMessages(['pic_id' => 'Tetapkan PIC sebelum memindahkan tugas dari Back Log.']);
            }

            $locked->status = $status;
            $locked->completed_at = $status === TaskStatus::Done ? now() : null;

            $changes = $this->describeChanges($locked);

            $locked->version = $baseVersion + 1;
            $locked->save();

            $locked->activities()->create([
                'account_id' => $actor->id,
                'action' => TaskActivity::STATUS_CHANGED,
                'changes' => $changes,
            ]);
        });

        return $this->detail($task->refresh());
    }

    /**
     * Assign, reassign, or forward the task to a new PIC. Admin may forward to any division; a Manager only
     * within the division they manage. The status is left as it is, and the change is appended to the history
     * so earlier assignments stay on record. A rejected change leaves the current PIC untouched.
     *
     * @param  array{version: int|string, pic_id: int|string, division_id?: int|string}  $data
     *
     * @throws TaskVersionConflictException When the task was saved by someone else after the actor loaded it.
     * @throws ValidationException When the actor may not forward to that division, the PIC is locked by the
     *                             task's status, or the chosen PIC already holds the task.
     */
    public function assign(Account $actor, Task $task, array $data): Task
    {
        $baseVersion = (int) $data['version'];

        DB::transaction(function () use ($actor, $task, $data, $baseVersion) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $baseVersion) {
                throw new TaskVersionConflictException($locked->version);
            }

            $lockedFields = $locked->lockedFieldsFor($actor);
            $previousPicId = $locked->pic_id;

            $locked->fill([
                'division_id' => $data['division_id'] ?? $locked->division_id,
                'pic_id' => $data['pic_id'],
            ]);

            if (! $locked->isDirty()) {
                throw ValidationException::withMessages(['pic_id' => 'PIC yang dipilih sudah menjadi PIC tugas ini.']);
            }

            if (! $actor->isAdmin() && isset($lockedFields['division_id'])) {
                $lockedFields['division_id'] = 'Anda tidak dapat meneruskan tugas ke divisi di luar kewenangan Anda.';
            }

            $errors = array_intersect_key($lockedFields, $locked->getDirty());

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $changes = $this->describeChanges($locked);

            $locked->version = $baseVersion + 1;
            $locked->save();

            $locked->activities()->create([
                'account_id' => $actor->id,
                'action' => $previousPicId === null ? TaskActivity::ASSIGNED : TaskActivity::REASSIGNED,
                'changes' => $changes,
            ]);
        });

        return $this->detail($task->refresh());
    }

    /**
     * Choices for the Assign PIC form: the current PIC, the divisions the actor may assign within with each
     * division's eligible PICs, and the reason the PIC cannot be changed right now, if any.
     *
     * @return array<string, mixed>
     */
    public function assignmentOptions(Account $actor, Task $task): array
    {
        $task->loadMissing(['pic', 'division']);

        return [
            'current_pic' => $task->pic?->only(['id', 'fullname']),
            'current_division' => $task->division->only(['id', 'code_division', 'name']),
            'can_change_division' => $actor->isAdmin(),
            'locked_reason' => $task->lockedFieldsFor($actor)['pic_id'] ?? null,
            'divisions' => $this->divisionsWithPics($actor),
        ];
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
            'divisions' => $this->divisionsWithPics($account),
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
     * Divisions the account may create or assign tasks in, each with the Staff eligible to be PIC there.
     *
     * @return array<int, array<string, mixed>>
     */
    private function divisionsWithPics(Account $account): array
    {
        return Division::query()
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
            ->all();
    }

    /**
     * Identify a submission by what would be saved, not by how it was sent: key order, an omitted field
     * versus an empty one, "5" versus 5, and an explicit default versus none all give the same fingerprint.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submissionFingerprint(array $attributes): string
    {
        $normalized = collect($attributes)
            ->reject(fn (mixed $value) => $value === null || $value === '')
            ->map(fn (mixed $value) => is_string($value) && ctype_digit($value) ? (int) $value : $value)
            ->sortKeys()
            ->all();

        return 'task-create:'.sha1(json_encode($normalized));
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
     * A category that requires QA keeps QA required: choosing such a category turns it on, and it cannot be
     * turned off while the task has one.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function applyCategoryReview(Task $task, array $data): void
    {
        if ($task->category_id === null || ! TaskCategory::whereKey($task->category_id)->value('requires_review')) {
            return;
        }

        if (array_key_exists('requires_review', $data) && ! $data['requires_review']) {
            throw ValidationException::withMessages(['requires_review' => 'Kategori tugas ini mewajibkan QA.']);
        }

        if ($task->isDirty('category_id')) {
            $task->requires_review = true;
        }
    }

    /**
     * Why the editor may not move the task, in its condition before the edit, to the sent status; null when
     * no other status is sent or the move is allowed.
     */
    private function statusDenial(Account $editor, Task $task, ?string $status): ?string
    {
        if ($status === null || $status === $task->status->value) {
            return null;
        }

        return $task->statusChangeDenialFor($editor, TaskStatus::from($status));
    }

    /**
     * @param  ?string  $statusDenial  Why the sent status change is not allowed, if it is not.
     * @param  array<string, string>  $lockedFields  Fields locked in the task's condition before the edit, with the reason.
     *
     * @throws ValidationException
     */
    private function guardChanges(Task $task, ?string $statusDenial, array $lockedFields): void
    {
        $errors = array_intersect_key($lockedFields, $task->getDirty());

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($statusDenial !== null) {
            throw ValidationException::withMessages(['status' => $statusDenial]);
        }

        if ($task->isDirty(['status', 'pic_id']) && $task->status !== TaskStatus::Backlog && $task->pic_id === null) {
            throw ValidationException::withMessages(['pic_id' => 'PIC wajib diisi untuk tugas yang tidak berstatus Back Log.']);
        }
    }

    /**
     * Old and new value of each changed field. Related records are kept with their name at the time
     * of the change, so the history stays readable after they are renamed.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function describeChanges(Task $task): array
    {
        $relations = [
            'category_id' => ['category', TaskCategory::class, 'name'],
            'division_id' => ['division', Division::class, 'name'],
            'platform_id' => ['platform', Platform::class, 'name'],
            'pic_id' => ['pic', Account::class, 'fullname'],
        ];

        return collect($task->getDirty())
            ->keys()
            ->reject(fn (string $field) => $field === 'completed_at')
            ->mapWithKeys(function (string $field) use ($task, $relations) {
                if (isset($relations[$field])) {
                    [$name, $model, $column] = $relations[$field];

                    return [$name => [
                        'old' => $this->reference($model, $column, $task->getOriginal($field)),
                        'new' => $this->reference($model, $column, $task->{$field}),
                    ]];
                }

                return [$field => [
                    'old' => $this->plain($task->getOriginal($field)),
                    'new' => $this->plain($task->{$field}),
                ]];
            })
            ->all();
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{id: int, name: ?string}|null
     */
    private function reference(string $model, string $column, mixed $id): ?array
    {
        return $id === null ? null : ['id' => (int) $id, 'name' => $model::whereKey($id)->value($column)];
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toDateString(),
            default => $value,
        };
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
