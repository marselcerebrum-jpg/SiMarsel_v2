<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'reviewer_id', 'decision', 'note'])]
class TaskReview extends Model
{
    public const APPROVED = 'approved';
    public const REVISION_REQUESTED = 'revision_requested';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'reviewer_id');
    }
}
