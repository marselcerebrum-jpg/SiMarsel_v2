<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Division;

class DivisionPolicy
{
    /**
     * Every logged-in role can view divisions.
     */
    public function viewAny(Account $user): bool
    {
        return true;
    }

    /**
     * Every logged-in role can view a division.
     */
    public function view(Account $user, Division $division): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create divisions.
     */
    public function create(Account $user): bool
    {
        return $user->isManager();
    }

    /**
     * Determine whether the user can update the division.
     */
    public function update(Account $user, Division $division): bool
    {
        return $user->isManager();
    }

    /**
     * Determine whether the user can delete the division.
     */
    public function delete(Account $user, Division $division): bool
    {
        return $user->isManager();
    }
}
