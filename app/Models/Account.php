<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['role_id', 'division_id', 'fullname', 'username', 'password'])]
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

    public function isManager(): bool
    {
        return $this->role?->code_role === Role::MANAGER;
    }
}
