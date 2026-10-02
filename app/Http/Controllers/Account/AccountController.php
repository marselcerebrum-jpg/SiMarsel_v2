<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function __construct(private AccountService $accountService) {}

    public function index(): AnonymousResourceCollection
    {
        return AccountResource::collection($this->accountService->list());
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->create($request->validated());

        return AccountResource::make($account)
            ->additional(['message' => 'Akun berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account = $this->accountService->update($account, $request->validated());

        return AccountResource::make($account)->additional([
            'message' => 'Akun berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->accountService->delete($account, $request->user());

        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }
}
