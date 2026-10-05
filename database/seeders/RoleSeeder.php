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
            ['code_role' => 'MGR', 'name' => 'Manager'],
            ['code_role' => 'EMP', 'name' => 'Employee'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code_role' => $role['code_role']], $role);
        }
    }
}
