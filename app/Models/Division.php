<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code_division', 'name'])]
class Division extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Divisions the account may create tasks for: every division for Admin, only the managed one for a Manager.
     */
    public function scopeTaskCreatableBy(Builder $query, Account $account): void
    {
        if ($account->isAdmin()) {
            return;
        }

        $query->whereKey($account->isManager() ? ($account->division_id ?? 0) : 0);
    }
}
