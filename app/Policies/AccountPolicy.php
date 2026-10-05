<?php

namespace App\Policies;

use App\Models\Account;

class AccountPolicy
{
    /**
     * Determine whether the user can view any accounts.
     */
    public function viewAny(Account $user): bool
    {
        return $user->isManager();
    }

    /**
     * Determine whether the user can register new accounts.
     */
    public function create(Account $user): bool
    {
        return $user->isManager();
    }

    /**
     * Determine whether the user can update the account.
     */
    public function update(Account $user, Account $account): bool
    {
        return $user->isManager();
    }

    /**
     * Determine whether the user can delete the account.
     */
    public function delete(Account $user, Account $account): bool
    {
        return $user->isManager();
    }
}
