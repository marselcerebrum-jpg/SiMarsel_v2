<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['role_id', 'division_id', 'fullname', 'username', 'password', 'is_active'])]
#[Hidden(['password'])]
class Account extends Authenticatable
{
    public const USERNAME_FORMAT = '/^[a-zA-Z0-9._]{4,25}$/';

    /**
     * The accounts table has no remember_token column.
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Accounts that can be PIC of a task: active Staff, Freelance, and Supervisors of any division, so a task can
     * be assigned across divisions.
     */
    public function scopeAssignableAsPic(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('role', fn (Builder $query) => $query->whereIn('code_role', Role::PIC_ROLES));
    }

    public function isAdmin(): bool
    {
        return $this->role?->code_role === Role::ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role?->code_role === Role::MANAGER;
    }

    public function isSupervisor(): bool
    {
        return $this->role?->code_role === Role::SUPERVISOR;
    }
}
