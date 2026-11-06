<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActivityImmutableException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a task's history. changes maps each changed field to its old and new value.
 * Entries are append-only: once written they are never updated or deleted.
 */
#[Fillable(['task_id', 'account_id', 'action', 'changes'])]
class TaskActivity extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const ASSIGNED = 'assigned';

    public const REASSIGNED = 'reassigned';

    public const STATUS_CHANGED = 'status_changed';

    public const EVIDENCE_SUBMITTED = 'evidence_submitted';

    public const APPROVED = 'approved';

    public const REVISION_REQUESTED = 'revision_requested';

    /**
     * The PIC handed the task back to QA after a revision.
     */
    public const RESUBMITTED = 'resubmitted';

    public const ACTION_LABELS = [
        self::CREATED => 'Tugas dibuat',
        self::UPDATED => 'Informasi tugas diubah',
        self::ASSIGNED => 'PIC ditetapkan',
        self::REASSIGNED => 'PIC diganti',
        self::STATUS_CHANGED => 'Status diubah',
        self::EVIDENCE_SUBMITTED => 'Evidence dikirim',
        self::APPROVED => 'Tugas disetujui',
        self::REVISION_REQUESTED => 'Revisi diminta',
        self::RESUBMITTED => 'Hasil revisi dikirim ulang',
    ];

    private const FIELD_LABELS = [
        'title' => 'Judul',
        'description' => 'Deskripsi',
        'category' => 'Kategori',
        'division' => 'Divisi',
        'platform' => 'Platform',
        'pic' => 'PIC',
        'priority' => 'Prioritas',
        'status' => 'Status',
        'start_date' => 'Tanggal mulai',
        'deadline' => 'Deadline',
        'requires_review' => 'Wajib QA',
        'evidence_required' => 'Wajib evidence',
    ];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new TaskActivityImmutableException);
        static::deleting(fn () => throw new TaskActivityImmutableException);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * Human-readable summary of what changed, built from the stored old and new values.
     */
    public function description(): string
    {
        // $this->changes is Eloquent's own dirty-tracking property, not the column.
        $changes = $this->getAttribute('changes') ?? [];
        $parts = [];

        foreach ($changes as $field => $change) {
            if ($field === 'evidence' || $field === 'review') {
                continue;
            }

            $label = self::FIELD_LABELS[$field] ?? $field;

            $parts[] = $field === 'description'
                ? "{$label} diperbarui"
                : "{$label}: {$this->formatValue($field, $change['old'] ?? null)} → {$this->formatValue($field, $change['new'] ?? null)}";
        }

        if ($evidence = $changes['evidence']['new'] ?? null) {
            $parts[] = 'Evidence: '.($evidence['name'] ?? '-');
        }

        if ($note = $changes['review']['new']['note'] ?? null) {
            $parts[] = "Catatan: {$note}";
        }

        if ($parts === []) {
            return $this->action === self::CREATED ? 'Membuat tugas.' : $this->actionLabel().'.';
        }

        return implode('; ', $parts);
    }

    private function formatValue(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '-',
            is_array($value) => (string) ($value['name'] ?? '-'),
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            $field === 'status' => TaskStatus::tryFrom($value)?->label() ?? (string) $value,
            $field === 'priority' => TaskPriority::tryFrom($value)?->label() ?? (string) $value,
            default => (string) $value,
        };
    }
}
