<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Funnel;

/**
 * Funnels are managed by the Sales/Marketing users of the promo module: every logged-in account.
 */
class FunnelPolicy
{
    public function viewAny(Account $user): bool
    {
        return true;
    }

    public function view(Account $user, Funnel $funnel): bool
    {
        return true;
    }

    public function create(Account $user): bool
    {
        return true;
    }

    public function update(Account $user, Funnel $funnel): bool
    {
        return true;
    }

    public function delete(Account $user, Funnel $funnel): bool
    {
        return true;
    }
}
