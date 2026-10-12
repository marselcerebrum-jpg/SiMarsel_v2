<?php

namespace Tests\Feature\Division;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateDivisionTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Account $employee;

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
    }

    public function test_guest_cannot_create_a_division(): void
    {
        $this->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Silakan login terlebih dahulu.']);

        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_employee_cannot_create_a_division(): void
    {
        $this->actingAs($this->employee)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Anda tidak memiliki akses untuk melakukan aksi ini.']);

        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_manager_can_create_an_active_division(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertCreated()
            ->assertJsonPath('message', 'Divisi berhasil dibuat.')
            ->assertJsonPath('data.code_division', 'IT')
            ->assertJsonPath('data.name', 'Information Tech')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('divisions', [
            'code_division' => 'IT',
            'name' => 'Information Tech',
            'is_active' => true,
        ]);
    }

    public function test_is_active_cannot_be_set_from_request(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech', 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_created_division_can_be_assigned_to_an_account(): void
    {
        $divisionId = $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->json('data.id');

        $this->patchJson("/api/accounts/{$this->employee->id}", ['division_id' => $divisionId])
            ->assertOk()
            ->assertJsonPath('data.division.code_division', 'IT');
    }

    public function test_code_and_name_are_required(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => '', 'name' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code_division' => 'Kode divisi wajib diisi.',
                'name' => 'Nama divisi wajib diisi.',
            ]);

        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_code_and_name_must_be_unique(): void
    {
        Division::create(['code_division' => 'IT', 'name' => 'Information Tech']);

        $this->actingAs($this->manager)
            ->postJson('/api/divisions', ['code_division' => 'IT', 'name' => 'Information Tech'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code_division' => 'Kode divisi sudah digunakan.',
                'name' => 'Nama divisi sudah digunakan.',
            ]);

        $this->assertDatabaseCount('divisions', 1);
    }

    public function test_code_and_name_must_fit_column_limits(): void
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
}
