<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Role;
use Illuminate\View\View;

class AccountPageController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.settings.index', [
            'roles' => Role::orderBy('id')->get(['id', 'code_role', 'name']),
            'divisions' => Division::orderBy('name')->get(['id', 'code_division', 'name']),
        ]);
    }
}
