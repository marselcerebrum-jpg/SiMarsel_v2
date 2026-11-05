<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'submitter_id', 'type', 'file_path', 'file_name', 'url', 'note'])]
class TaskEvidence extends Model
{
    // "evidence" is uncountable to the pluralizer, which would otherwise look for task_evidence.
    protected $table = 'task_evidences';

    public const FILE = 'file';

    public const LINK = 'link';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'submitter_id');
    }
}
