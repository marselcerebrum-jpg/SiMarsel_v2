<?php

namespace Tests\Feature\Database;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDivisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_division_sets_account_division_to_null(): void
    {
        $role = Role::create(['code_role' => 'EMP', 'name' => 'Employee']);
        $division = Division::create(['code_division' => 'IT', 'name' => 'Information Tech']);

        $account = Account::create([
            'role_id' => $role->id,
            'division_id' => $division->id,
            'fullname' => 'Test Employee',
            'username' => 'employee',
            'password' => 'secret123',
        ]);

        $division->delete();

        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'division_id' => null]);
    }
}
