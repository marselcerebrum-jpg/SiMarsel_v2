<?php

namespace App\Services;

use App\Exceptions\CannotDeleteOwnAccountException;
use App\Models\Account;
use App\Repositories\AccountRepository;
use Illuminate\Database\Eloquent\Collection;

class AccountService
{
    public function __construct(private AccountRepository $accountRepository) {}

    /**
     * @return Collection<int, Account>
     */
    public function list(): Collection
    {
        return $this->accountRepository->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return $this->accountRepository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        return $this->accountRepository->update($account, $data);
    }

    /**
     * @throws CannotDeleteOwnAccountException
     */
    public function delete(Account $account, Account $actor): void
    {
        if ($account->is($actor)) {
            throw new CannotDeleteOwnAccountException;
        }

        $this->accountRepository->delete($account);
    }
}
