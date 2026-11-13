<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code_role', 'name'])]
class Role extends Model
{
    public const ADMIN = 'ADM';

    public const MANAGER = 'MGR';

    public const SUPERVISOR = 'SPV';

    /**
     * Shown as Staff; the code is kept so existing accounts keep their role.
     */
    public const EMPLOYEE = 'EMP';

    public const FREELANCE = 'FRL';

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function hasPermission(string $code): bool
    {
        return $this->permissions->contains('code', $code);
    }
}
