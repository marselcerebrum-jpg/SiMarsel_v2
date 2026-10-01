<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Seed the default manager account, since the app has no registration.
     */
    public function run(): void
    {
        Account::updateOrCreate(
            ['username' => 'manager'],
            [
                'role_id' => Role::where('code_role', 'MGR')->value('id'),
                'division_id' => null,
                'fullname' => 'Default Manager',
                'password' => 'Manager123!',
            ],
        );
    }
}
