<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountPageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tab = $request->query('tab');

        return view('pages.settings.index', [
            'initialTab' => in_array($tab, ['accounts', 'divisions'], true) ? $tab : 'accounts',
            'roles' => Role::orderBy('id')->get(['id', 'code_role', 'name']),
            'divisions' => Division::orderBy('name')->get(['id', 'code_division', 'name']),
        ]);
    }
}
