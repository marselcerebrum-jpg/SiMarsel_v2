<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        Permission::TASK_VIEW_ALL => 'Melihat daftar dan detail tugas seluruh divisi.',
        Permission::TASK_CREATE => 'Membuat tugas.',
        Permission::TASK_UPDATE => 'Mengubah informasi tugas.',
        Permission::TASK_DELETE => 'Menghapus tugas.',
        Permission::TASK_ASSIGN => 'Menetapkan dan melepas PIC tugas.',
        Permission::TASK_REVIEW => 'Memberi keputusan review (Approve atau Request Revision).',
        Permission::TASK_CHANGE_STATUS => 'Mengubah status tugas.',
    ];

    /**
     * Admin, Manager, and Supervisor manage tasks of every division. Staff and Freelance work on the tasks they are
     * PIC of; which tasks and fields that covers is decided by the Backend, not by the permission alone.
     */
    private const ROLE_PERMISSIONS = [
        Role::ADMIN => '*',
        Role::MANAGER => '*',
        Role::SUPERVISOR => '*',
        Role::EMPLOYEE => [Permission::TASK_VIEW_ALL, Permission::TASK_UPDATE, Permission::TASK_CHANGE_STATUS],
        Role::FREELANCE => [Permission::TASK_VIEW_ALL, Permission::TASK_UPDATE, Permission::TASK_CHANGE_STATUS],
    ];

    /**
     * Requires RoleSeeder. Safe to run again: permissions are matched by code and each role's set is synced.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            Permission::updateOrCreate(['code' => $code], ['description' => $description]);
        }

        $permissionIds = Permission::whereIn('code', array_keys(self::PERMISSIONS))->pluck('id', 'code');

        foreach (self::ROLE_PERMISSIONS as $code => $granted) {
            $ids = $granted === '*' ? $permissionIds->values() : $permissionIds->only($granted)->values();

            Role::where('code_role', $code)->first()?->permissions()->sync($ids);
        }
    }
}
