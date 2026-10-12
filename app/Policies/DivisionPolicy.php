<?php

namespace App\Policies;

use App\Models\Account;

class DivisionPolicy
{
    /**
     * Determine whether the user can create divisions.
     */
    public function create(Account $user): bool
    {
        return $user->isManager();
    }
}
