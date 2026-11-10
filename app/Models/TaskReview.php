<?php

namespace App\Models;

use App\Enums\TaskReviewDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One QA decision on a task. cycle counts the reviews of the task, so the first review is 1 and each
 * resubmission after a revision gets the next number. Reviews are never edited once recorded.
 */
#[Fillable(['task_id', 'reviewer_id', 'cycle', 'decision', 'note'])]
class TaskReview extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => TaskReviewDecision::class,
            'cycle' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'reviewer_id');
    }
}
