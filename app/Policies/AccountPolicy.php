<?php

namespace App\Policies;

use App\Models\Account;

class AccountPolicy
{
    /**
     * Every logged-in role can view the account list.
     */
    public function viewAny(Account $user): bool
    {
        return true;
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
