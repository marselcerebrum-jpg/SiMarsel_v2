<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ScheduleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An agenda on the shared schedule (Timeline and Calendar), next to the tasks' own dates.
 *
 * Who may see it is decided by is_shared and the target divisions, never by the owner division: a shared
 * agenda is visible to everyone, an internal one to Admin and the accounts of its target divisions only.
 */
#[Fillable(['title', 'description', 'owner_division_id', 'start_date', 'end_date', 'is_shared', 'task_id', 'creator_id'])]
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

    /**
     * The division that owns or runs the agenda.
     */
    public function ownerDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'owner_division_id');
    }

    /**
     * The divisions an internal agenda is meant for.
     */
    public function targetDivisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class, 'schedule_event_divisions')->orderBy('divisions.id');
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
     * Focus on a division: the agendas it owns or is a target of, plus the shared agendas without an owner.
     * No division keeps every agenda. Visibility is applied separately, so the focus never widens it.
     */
    public function scopeForDivision(Builder $query, ?string $division): void
    {
        $query->when($division, fn (Builder $query, string $division) => $query->where(fn (Builder $query) => $query
            ->whereHas('ownerDivision', fn (Builder $query) => $query->where('code_division', $division))
            ->orWhereHas('targetDivisions', fn (Builder $query) => $query->where('code_division', $division))
            ->orWhere(fn (Builder $query) => $query->where('is_shared', true)->whereNull('owner_division_id'))));
    }

    /**
     * is_shared decides first: a shared agenda is visible to everyone. An internal agenda is visible to Admin
     * and to the accounts of its target divisions; without a target division only to Admin.
     */
    public function scopeVisibleTo(Builder $query, Account $account): void
    {
        if ($account->isAdmin()) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('is_shared', true)
            ->orWhereHas('targetDivisions', fn (Builder $query) => $query->whereKey($account->division_id ?? 0)));
    }
}
