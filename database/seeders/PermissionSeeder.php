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

        $permissions = collect($arrPermissions)->map(function ($permission) {
            return ['name' => $permission, 'guard_name' => 'web'];
        });
        Permission::insert($permissions->toArray());
        //update permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createRole(): void
    {
        $role = Role::create(['name' => 'Manager']);
        $role->givePermissionTo([
            'access_admin',
            'user_read',
            'role_read',
            'user_update',
            'user_create',
            'user_delete',
        ]);
        
        $role = Role::create(['name' => 'Default']);
        $role->givePermissionTo([
            'access_admin',
        ]);
        
        $role = Role::create(['name' => 'Analista de Contrato']);
        $role->givePermissionTo([
            'analyze_contract',
        ]);

        $role = Role::create(['name' => 'Analista de Processo']);
        $role->givePermissionTo([
            'analyze_process',
        ]);

        $role = Role::create(['name' => 'Admin']);
        $role->givePermissionTo(Permission::all());

    }
}
