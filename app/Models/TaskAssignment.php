<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account assigned as PIC of a task. It is active while unassigned_at is null; ending it keeps the row,
 * so the assignment history is never lost. A task may have several active PICs.
 */
#[Fillable(['task_id', 'account_id', 'assigned_by', 'assigned_at', 'unassigned_by', 'unassigned_at', 'note'])]
class TaskAssignment extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'assigned_by');
    }

    public function unassigner(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'unassigned_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('unassigned_at');
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }
}
