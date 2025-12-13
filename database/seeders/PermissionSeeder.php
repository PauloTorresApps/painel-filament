<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $this->createPermission();
        $this->createRole();
    }

    private function createPermission(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $arrPermissions = [
            'access_admin',
            'user_create',
            'user_update',
            'user_delete',
            'user_read',
            'role_create',
            'role_update',
            'role_read',
            'role_delete',
            'permission_create',
            'permission_update',
            'permission_read',
            'permission_delete',
            'analyze_process',
            'analyze_contract',
        ];

        foreach ($arrPermissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }

        //update permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createRole(): void
    {
        $role = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $role->syncPermissions([
            'access_admin',
            'user_read',
            'role_read',
            'user_update',
            'user_create',
            'user_delete',
        ]);

        $role = Role::firstOrCreate(['name' => 'Default', 'guard_name' => 'web']);
        $role->syncPermissions([
            'access_admin',
        ]);

        $role = Role::firstOrCreate(['name' => 'Analista de Contrato', 'guard_name' => 'web']);
        $role->syncPermissions([
            'access_admin',
            'analyze_contract',
        ]);

        $role = Role::firstOrCreate(['name' => 'Analista de Processo', 'guard_name' => 'web']);
        $role->syncPermissions([
            'access_admin',
            'analyze_process',
        ]);

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::all());
    }
}
