<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code_role', 'name'])]
class Role extends Model
{
    public const MANAGER = 'MGR';

    public const EMPLOYEE = 'EMP';

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
