<?php

namespace Tests\Feature\Database;

use App\Models\Account;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_account_deletes_its_sessions(): void
    {
        $role = Role::create(['code_role' => 'EMP', 'name' => 'Employee']);

        $account = Account::create([
            'role_id' => $role->id,
            'fullname' => 'Test Employee',
            'username' => 'employee',
            'password' => 'secret123',
        ]);

        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $account->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $account->delete();

        $this->assertDatabaseMissing('sessions', ['id' => 'test-session-id']);
    }
}
