<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\PromoCode;

/**
 * Promo codes are managed by the Sales/Marketing users of the promo module: every logged-in account.
 */
class PromoCodePolicy
{
    public function viewAny(Account $user): bool
    {
        return true;
    }

    public function view(Account $user, PromoCode $promoCode): bool
    {
        return true;
    }

    public function create(Account $user): bool
    {
        return true;
    }

    public function update(Account $user, PromoCode $promoCode): bool
    {
        return true;
    }

    public function delete(Account $user, PromoCode $promoCode): bool
    {
        return true;
    }
}
