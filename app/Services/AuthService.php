<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Authenticate the account and start a fresh session.
     *
     * @param  array{username: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    public function login(array $credentials, Request $request): Account
    {
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        $request->session()->regenerate();

        return Auth::user()->load(['role', 'division']);
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
