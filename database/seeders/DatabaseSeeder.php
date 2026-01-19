<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // First, create permissions and roles
        $this->call([
            PermissionSeeder::class,
            SystemSeeder::class,
            AiModelsSeeder::class,
        ]);

        // Then create user and assign Admin role
        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => '123456789',
                'email_verified_at' => now(),
            ]
        );

        // Assign Admin role to the user
        $user->assignRole('Admin');
    }
}
