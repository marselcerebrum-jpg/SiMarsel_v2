<?php

namespace App\Models;

use App\Services\TaskEvidenceService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'submitter_id', 'type', 'file_path', 'file_name', 'mime_type', 'file_size', 'url', 'note', 'deleted_by'])]
class TaskEvidence extends Model
{
    use SoftDeletes;

    // "evidence" is uncountable to the pluralizer, which would otherwise look for task_evidence.
    protected $table = 'task_evidences';

    public const FILE = 'file';

    public const LINK = 'link';

    public const KIND_DOCUMENT = 'document';

    public const KIND_IMAGE = 'image';

    public const KIND_LINK = 'link';

    /**
     * Image types shown inline as a preview. SVG is left out on purpose: it can carry scripts.
     */
    public const PREVIEWABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * MIME types of older files stored before the MIME type was recorded, read from their file name.
     */
    private const MIME_TYPES_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'submitter_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deleted_by');
    }

    /**
     * The MIME type recorded at upload, or for older files the one their name implies. Null for links.
     */
    public function effectiveMimeType(): ?string
    {
        if ($this->type !== self::FILE) {
            return null;
        }

        return $this->mime_type
            ?? self::MIME_TYPES_BY_EXTENSION[strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * What the evidence is for display: an image, a document (every other file), or a link.
     */
    public function kind(): string
    {
        if ($this->type !== self::FILE) {
            return self::KIND_LINK;
        }

        return str_starts_with((string) $this->effectiveMimeType(), 'image/') ? self::KIND_IMAGE : self::KIND_DOCUMENT;
    }

    public function previewable(): bool
    {
        return in_array($this->effectiveMimeType(), self::PREVIEWABLE_MIME_TYPES, true);
    }

    /**
     * Whether the account may delete this evidence right now: only the PIC who uploaded it, while still an active PIC
     * with task.update, and only while the task still accepts evidence (Back Log, To Do, On Process), so evidence that is being
     * or has been reviewed stays. Evidence without a known uploader cannot be deleted.
     */
    public function deletableBy(Account $account): bool
    {
        return $this->submitter_id !== null
            && $this->submitter_id === $account->id
            && $account->hasPermission(Permission::TASK_UPDATE)
            && in_array($this->task->status, TaskEvidenceService::SUBMITTABLE_STATUSES, true)
            && $this->task->assignedTo($account);
    }
}
