<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['code_role' => Role::ADMIN, 'name' => 'Admin'],
            ['code_role' => Role::MANAGER, 'name' => 'Manager'],
            // Existing roles first, so a fresh database gives them the same ids as the existing ones.
            ['code_role' => Role::EMPLOYEE, 'name' => 'Staff'],
            ['code_role' => Role::SUPERVISOR, 'name' => 'Supervisor'],
            ['code_role' => Role::FREELANCE, 'name' => 'Freelance'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code_role' => $role['code_role']], $role);
        }
    }
}
