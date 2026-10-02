<?php

namespace App\Repositories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;

class AccountRepository
{
    /**
     * @return Collection<int, Account>
     */
    public function all(): Collection
    {
        return Account::with(['role', 'division'])->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return Account::create($data)->load(['role', 'division']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        $account->update($data);

        return $account->load(['role', 'division']);
    }

    public function delete(Account $account): void
    {
        $account->delete();
    }
}
