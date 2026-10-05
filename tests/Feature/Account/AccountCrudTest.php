<?php

namespace Tests\Feature\Account;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Account $employee;

    private Role $employeeRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->employeeRole = Role::where('code_role', Role::EMPLOYEE)->first();
        $this->employee = Account::create([
            'role_id' => $this->employeeRole->id,
            'fullname' => 'Existing Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'fullname' => 'New Employee',
            'username' => 'new.employee',
            'password' => 'Secret123!',
            'role_id' => $this->employeeRole->id,
            'division_id' => null,
        ], $overrides);
    }

    public function test_guest_cannot_access_account_endpoints(): void
    {
        $this->getJson('/api/accounts')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Silakan login terlebih dahulu.']);
        $this->postJson('/api/accounts', $this->validPayload())->assertUnauthorized();
        $this->putJson("/api/accounts/{$this->employee->id}", ['fullname' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/accounts/{$this->employee->id}")->assertUnauthorized();
    }

    public function test_employee_cannot_access_account_endpoints(): void
    {
        $this->actingAs($this->employee);

        $this->getJson('/api/accounts')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Anda tidak memiliki akses untuk melakukan aksi ini.']);
        $this->postJson('/api/accounts', $this->validPayload())->assertForbidden();
        $this->putJson("/api/accounts/{$this->manager->id}", ['fullname' => 'X'])->assertForbidden();
        $this->deleteJson("/api/accounts/{$this->manager->id}")->assertForbidden();

        $this->assertDatabaseCount('accounts', 2);
    }

    public function test_manager_can_list_all_accounts(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.username', 'manager')
            ->assertJsonPath('data.0.role.code_role', Role::MANAGER)
            ->assertJsonPath('data.1.username', 'employee')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_manager_can_register_new_account(): void
    {
        $division = Division::create(['code_division' => 'IT', 'name' => 'Information Tech']);

        $this->actingAs($this->manager)
            ->postJson('/api/accounts', $this->validPayload(['division_id' => $division->id]))
            ->assertCreated()
            ->assertJson([
                'message' => 'Akun berhasil dibuat.',
                'data' => [
                    'fullname' => 'New Employee',
                    'username' => 'new.employee',
                    'role' => ['code_role' => Role::EMPLOYEE],
                    'division' => ['code_division' => 'IT'],
                ],
            ])
            ->assertJsonMissingPath('data.password');

        $account = Account::where('username', 'new.employee')->first();
        $this->assertTrue(Hash::check('Secret123!', $account->password));
    }

    public function test_registered_account_can_login(): void
    {
        $this->actingAs($this->manager)->postJson('/api/accounts', $this->validPayload());
        $this->app['auth']->guard('web')->logout();

        $this->postJson('/api/login', ['username' => 'new.employee', 'password' => 'Secret123!'])
            ->assertOk()
            ->assertJsonPath('data.username', 'new.employee');
    }

    public function test_register_validates_input(): void
    {
        $this->actingAs($this->manager);

        $this->postJson('/api/accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fullname', 'username', 'password', 'role_id']);

        $this->postJson('/api/accounts', $this->validPayload(['username' => 'employee']))
            ->assertJsonValidationErrors(['username' => 'Username sudah digunakan.']);

        $this->postJson('/api/accounts', $this->validPayload(['username' => 'a b']))
            ->assertJsonValidationErrors('username');

        $this->postJson('/api/accounts', $this->validPayload(['password' => 'short']))
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/accounts', $this->validPayload(['role_id' => 999, 'division_id' => 999]))
            ->assertJsonValidationErrors(['role_id', 'division_id']);

        $this->assertDatabaseCount('accounts', 2);
    }

    public function test_manager_can_update_account_by_id(): void
    {
        $division = Division::create(['code_division' => 'HR', 'name' => 'Human Resource']);

        $this->actingAs($this->manager)
            ->putJson("/api/accounts/{$this->employee->id}", [
                'fullname' => 'Updated Employee',
                'division_id' => $division->id,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Akun berhasil diperbarui.',
                'data' => [
                    'id' => $this->employee->id,
                    'fullname' => 'Updated Employee',
                    'username' => 'employee',
                    'division' => ['code_division' => 'HR'],
                ],
            ]);

        $this->assertTrue(Hash::check('Employee123!', $this->employee->fresh()->password));
    }

    public function test_manager_can_change_account_password(): void
    {
        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$this->employee->id}", ['password' => 'NewSecret123!'])
            ->assertOk();

        $this->assertTrue(Hash::check('NewSecret123!', $this->employee->fresh()->password));
    }

    public function test_update_keeps_own_username_but_rejects_taken_username(): void
    {
        $this->actingAs($this->manager);

        $this->putJson("/api/accounts/{$this->employee->id}", ['username' => 'employee'])
            ->assertOk();

        $this->putJson("/api/accounts/{$this->employee->id}", ['username' => 'manager'])
            ->assertJsonValidationErrors(['username' => 'Username sudah digunakan.']);
    }

    public function test_update_unknown_account_returns_not_found(): void
    {
        $this->actingAs($this->manager)
            ->putJson('/api/accounts/999', ['fullname' => 'Nobody'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
    }

    public function test_manager_can_delete_account_by_id(): void
    {
        $this->actingAs($this->manager)
            ->deleteJson("/api/accounts/{$this->employee->id}")
            ->assertOk()
            ->assertJson(['message' => 'Akun berhasil dihapus.']);

        $this->assertDatabaseMissing('accounts', ['id' => $this->employee->id]);
    }

    public function test_delete_unknown_account_returns_not_found(): void
    {
        $this->actingAs($this->manager)
            ->deleteJson('/api/accounts/999')
            ->assertNotFound();
    }

    public function test_manager_cannot_delete_own_account(): void
    {
        $this->actingAs($this->manager)
            ->deleteJson("/api/accounts/{$this->manager->id}")
            ->assertUnprocessable()
            ->assertJson(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.']);

        $this->assertDatabaseHas('accounts', ['id' => $this->manager->id]);
    }
}
