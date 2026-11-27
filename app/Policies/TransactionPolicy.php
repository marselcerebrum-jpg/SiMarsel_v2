<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Transaction;

/**
 * Daftar Transaksi follows the current Analisis Performa rule: every active account may read it. Contacts have
 * their own ability (viewContact), so they can be limited later without changing the list or the detail.
 */
class TransactionPolicy
{
    public function viewAny(Account $user): bool
    {
        return $user->is_active;
    }

    public function view(Account $user, Transaction $transaction): bool
    {
        return $user->is_active;
    }

    /**
     * Customer email and phone, returned only by the detail.
     */
    public function viewContact(Account $user, Transaction $transaction): bool
    {
        return $user->is_active;
    }
}
