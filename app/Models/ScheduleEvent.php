<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ScheduleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agenda on the shared schedule (Timeline and Calendar), next to the tasks' own dates.
 */
#[Fillable(['title', 'description', 'division_id', 'start_date', 'end_date', 'is_shared', 'task_id', 'creator_id'])]
class ScheduleEvent extends Model
{
    /** @use HasFactory<ScheduleEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_shared' => 'boolean',
        ];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'creator_id');
    }

    /**
     * Agendas that overlap the period; one without an end date lasts its start date only.
     */
    public function scopeWithin(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('start_date', '<=', $to->copy()->endOfDay()->toDateTimeString())
            ->whereRaw('coalesce(end_date, start_date) >= ?', [$from->copy()->startOfDay()->toDateTimeString()]);
    }

    /**
     * Shared agendas are visible to every role. An agenda that is not shared is visible to Admin and to the
     * accounts of its division; one of every division (no division) to everyone.
     */
    public function scopeVisibleTo(Builder $query, Account $account): void
    {
        if ($account->isAdmin()) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('is_shared', true)
            ->orWhereNull('division_id')
            ->orWhere('division_id', $account->division_id ?? 0));
    }
}
