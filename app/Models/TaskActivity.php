<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a task's history. changes maps each changed field to its old and new value.
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

    public const UPDATED_AT = null;

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
}
