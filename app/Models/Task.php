<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'description', 'category_id', 'division_id', 'platform_id', 'pic_id', 'creator_id', 'priority', 'status',
    'start_date', 'deadline', 'requires_review', 'evidence_required', 'completed_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Fields an Admin or the division's Manager may change through the edit form.
     */
    public const EDITABLE_FIELDS = [
        'title', 'description', 'category_id', 'division_id', 'platform_id', 'pic_id', 'priority', 'status',
        'start_date', 'deadline', 'requires_review',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'start_date' => 'date',
            'deadline' => 'date',
            'requires_review' => 'boolean',
            'evidence_required' => 'boolean',
            'completed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * History, newest first.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(TaskEvidence::class)->latest();
    }

    /**
     * QA decisions, newest first.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'pic_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'creator_id');
    }

    /**
     * Limit tasks to what the account may see: Admin sees every division, a Manager sees the
     * division they manage, and Staff only sees tasks assigned to them as PIC.
     */
    public function scopeVisibleTo(Builder $query, Account $account): void
    {
        if ($account->isAdmin()) {
            return;
        }

        if ($account->isManager()) {
            $query->where('division_id', $account->division_id ?? 0);

            return;
        }

        $query->where('pic_id', $account->id);
    }

    /**
     * Same rule as scopeVisibleTo(), checked against a loaded task.
     */
    public function isVisibleTo(Account $account): bool
    {
        return match (true) {
            $account->isAdmin() => true,
            $account->isManager() => $this->managedBy($account),
            default => $this->pic_id === $account->id,
        };
    }

    /**
     * Whether the account manages this task: Admin for every division, a Manager for their own.
     */
    public function managedBy(Account $account): bool
    {
        return $account->isAdmin()
            || ($account->isManager() && $account->division_id !== null && $this->division_id === $account->division_id);
    }

    public function assignedTo(Account $account): bool
    {
        return $this->pic_id !== null && $this->pic_id === $account->id;
    }

    /**
     * Seeing a task is not editing it: Admin and the division's Manager may edit it until it is Done, its PIC
     * only while there is a status step for them to take. Otherwise the task is read-only.
     */
    public function updatableBy(Account $account): bool
    {
        return ($this->managedBy($account) && $this->status !== TaskStatus::Done) || $this->nextStatusesFor($account) !== [];
    }

    /**
     * Statuses the account may move the task to from its current one: the PIC starts the work and hands it
     * to QA or finishes it; Admin or the division's Manager makes every other move.
     *
     * @return array<int, TaskStatus>
     */
    public function nextStatusesFor(Account $account): array
    {
        return $this->movesStatusFor($account) && ! $this->status->decidedByReview()
            ? $this->status->nextStatuses($this->requires_review)
            : [];
    }

    /**
     * Whether the account may run this task's QA: Admin for every division, a Manager for their own, and only
     * for a task that requires QA. Staff never reviews, not even their own work.
     */
    public function reviewableBy(Account $account): bool
    {
        return $this->requires_review && $this->managedBy($account);
    }

    /**
     * Whether the account may approve the task or request a revision right now.
     */
    public function awaitsDecisionFrom(Account $account): bool
    {
        return $this->status === TaskStatus::OnQa && $this->reviewableBy($account);
    }

    /**
     * Why the account may not move the task from its current status to the given one, or null when it may.
     */
    public function statusChangeDenialFor(Account $account, TaskStatus $status): ?string
    {
        $from = $this->status;

        if (! $from->canMoveTo($status, $this->requires_review)) {
            $reason = "Status tidak dapat diubah dari {$from->label()} ke {$status->label()}.";

            return $status === TaskStatus::Done && $this->requires_review
                ? $reason.' Tugas ini wajib melalui QA sebelum Done.'
                : $reason;
        }

        if (! $this->movesStatusFor($account)) {
            return $from->movedByPic()
                ? "Hanya PIC tugas yang dapat mengubah status dari {$from->label()} ke {$status->label()}."
                : "Hanya Admin atau Manajer divisi yang dapat mengubah status dari {$from->label()} ke {$status->label()}.";
        }

        if ($from->decidedByReview()) {
            return 'Keputusan QA diberikan melalui review tugas (Approve atau Request Revision).';
        }

        if ($from === TaskStatus::InProgress && $this->evidence_required && ! $this->evidences()->exists()) {
            return "Unggah evidence terlebih dahulu sebelum mengubah status ke {$status->label()}.";
        }

        return null;
    }

    private function movesStatusFor(Account $account): bool
    {
        return $this->status->movedByPic() ? $this->assignedTo($account) : $this->managedBy($account);
    }

    /**
     * Editable fields the account may not change in the task's current condition, with the reason.
     *
     * @return array<string, string>
     */
    public function lockedFieldsFor(Account $account): array
    {
        if (! $this->managedBy($account)) {
            return collect(self::EDITABLE_FIELDS)
                ->reject(fn (string $field) => $field === 'status')
                ->mapWithKeys(fn (string $field) => [$field => 'PIC hanya dapat mengubah status tugas.'])
                ->all();
        }

        if ($this->status === TaskStatus::Done) {
            return collect(self::EDITABLE_FIELDS)
                ->mapWithKeys(fn (string $field) => [$field => 'Tugas yang sudah selesai tidak dapat diubah.'])
                ->all();
        }

        $locked = [];

        if (! $account->isAdmin()) {
            $locked['division_id'] = 'Hanya Admin yang dapat memindahkan tugas ke divisi lain.';
        }

        if ($this->status->inQa()) {
            $locked['requires_review'] = 'Kewajiban QA tidak dapat diubah setelah tugas masuk tahap QA.';
            $locked['pic_id'] = 'PIC tidak dapat diganti selama tugas dalam tahap QA.';
        }

        return $locked;
    }

    /**
     * @return array<int, string>
     */
    public function editableFieldsFor(Account $account): array
    {
        if (! $this->updatableBy($account)) {
            return [];
        }

        $fields = array_diff(self::EDITABLE_FIELDS, array_keys($this->lockedFieldsFor($account)));

        if ($this->nextStatusesFor($account) === []) {
            $fields = array_diff($fields, ['status']);
        }

        return array_values($fields);
    }
}
