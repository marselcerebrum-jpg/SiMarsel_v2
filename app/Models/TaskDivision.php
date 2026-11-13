<?php

namespace App\Models;

use App\Exceptions\PrimaryDivisionCollaboratorException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * A collaborating division of a task. The task's owning division (tasks.division_id) is never stored here.
 */
class TaskDivision extends Pivot
{
    protected $table = 'task_divisions';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (TaskDivision $taskDivision) {
            $ownerDivisionId = DB::table('tasks')->where('id', $taskDivision->task_id)->value('division_id');

            if ($ownerDivisionId !== null && (int) $ownerDivisionId === (int) $taskDivision->division_id) {
                throw new PrimaryDivisionCollaboratorException;
            }

            $taskDivision->created_at ??= now();
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'added_by');
    }
}
