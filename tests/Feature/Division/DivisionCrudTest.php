<?php

namespace Tests\Feature\Division;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DivisionCrudTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Account $employee;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Existing Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
        $this->division = Division::create(['code_division' => 'IT', 'name' => 'Information Tech']);
    }

    public function test_guest_cannot_access_division_endpoints(): void
    {
        $this->getJson('/api/divisions')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Silakan login terlebih dahulu.']);
        $this->postJson('/api/divisions', ['code_division' => 'FIN', 'name' => 'Finance'])->assertUnauthorized();
        $this->getJson("/api/divisions/{$this->division->id}")->assertUnauthorized();
        $this->putJson("/api/divisions/{$this->division->id}", ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/divisions/{$this->division->id}")->assertUnauthorized();
    }

    public function test_employee_cannot_access_division_endpoints(): void
    {
        $this->actingAs($this->employee);

        $this->getJson('/api/divisions')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Anda tidak memiliki akses untuk melakukan aksi ini.']);
        $this->postJson('/api/divisions', ['code_division' => 'FIN', 'name' => 'Finance'])->assertForbidden();
        $this->getJson("/api/divisions/{$this->division->id}")->assertForbidden();
        $this->putJson("/api/divisions/{$this->division->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/divisions/{$this->division->id}")->assertForbidden();

        $this->assertDatabaseCount('divisions', 1);
    }

    public function test_manager_can_list_all_divisions(): void
    {
        Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->actingAs($this->manager)
            ->getJson('/api/divisions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => $this->division->id,
                'code_division' => 'IT',
                'name' => 'Information Tech',
            ])
            ->assertJsonPath('data.1.code_division', 'FIN');
    }

    public function test_manager_can_view_a_division(): void
    {
        $this->actingAs($this->manager)
            ->getJson("/api/divisions/{$this->division->id}")
            ->assertOk()
            ->assertJsonPath('data.code_division', 'IT')
            ->assertJsonPath('data.name', 'Information Tech');
    }

    public function test_manager_can_create_a_division(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'FIN', 'name' => 'Finance'])
            ->assertCreated()
            ->assertJsonPath('message', 'Divisi berhasil dibuat.')
            ->assertJsonPath('data.code_division', 'FIN')
            ->assertJsonPath('data.name', 'Finance');

        $this->assertDatabaseHas('divisions', ['code_division' => 'FIN', 'name' => 'Finance']);
    }

    public function test_create_requires_code_and_name(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code_division' => 'Kode divisi wajib diisi.',
                'name' => 'Nama divisi wajib diisi.',
            ]);
    }

    public function test_create_rejects_duplicate_code_and_name(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code_division' => 'Kode divisi sudah digunakan.',
                'name' => 'Nama divisi sudah digunakan.',
            ]);

        $this->assertDatabaseCount('divisions', 1);
    }

    public function test_create_rejects_values_longer_than_column_limits(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', [
                'code_division' => str_repeat('A', 51),
                'name' => str_repeat('a', 26),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code_division' => 'Kode divisi maksimal 50 karakter.',
                'name' => 'Nama divisi maksimal 25 karakter.',
            ]);
    }

    public function test_manager_can_update_only_sent_fields(): void
    {
        $this->actingAs($this->manager)
            ->patchJson("/api/divisions/{$this->division->id}", ['name' => 'IT Support'])
            ->assertOk()
            ->assertJsonPath('message', 'Divisi berhasil diperbarui.')
            ->assertJsonPath('data.code_division', 'IT')
            ->assertJsonPath('data.name', 'IT Support');

        $this->assertDatabaseHas('divisions', ['id' => $this->division->id, 'code_division' => 'IT', 'name' => 'IT Support']);
    }

    public function test_update_allows_keeping_own_code_and_name(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/divisions/{$this->division->id}", ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertOk();
    }

    public function test_update_rejects_code_and_name_used_by_another_division(): void
    {
        Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->actingAs($this->manager)
            ->patchJson("/api/divisions/{$this->division->id}", ['code_division' => 'FIN', 'name' => 'Finance'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code_division', 'name']);
    }

    public function test_manager_can_delete_a_division_and_its_accounts_keep_existing(): void
    {
        $this->employee->update(['division_id' => $this->division->id]);

        $this->actingAs($this->manager)
            ->deleteJson("/api/divisions/{$this->division->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Divisi berhasil dihapus.']);

        $this->assertDatabaseMissing('divisions', ['id' => $this->division->id]);
        $this->assertDatabaseHas('accounts', ['id' => $this->employee->id, 'division_id' => null]);
    }

    public function test_missing_division_returns_404(): void
    {
        $this->actingAs($this->manager);

        $this->getJson('/api/divisions/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->patchJson('/api/divisions/999', ['name' => 'X'])->assertNotFound();
        $this->deleteJson('/api/divisions/999')->assertNotFound();
    }
}
