<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\PromoSource;

/**
 * Sources are managed by the Sales/Marketing users of the promo module: every logged-in account.
 */
class PromoSourcePolicy
{
    public function viewAny(Account $user): bool
    {
        return true;
    }

    public function view(Account $user, PromoSource $promoSource): bool
    {
        return true;
    }

    public function create(Account $user): bool
    {
        return true;
    }

    public function update(Account $user, PromoSource $promoSource): bool
    {
        return true;
    }

    public function delete(Account $user, PromoSource $promoSource): bool
    {
        return true;
    }
}
