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

        // Primeiro, cria dados base e catálogos
        $this->call([
            PermissionSeeder::class,
            SystemSeeder::class,
            AiModelsSeeder::class,
            ContractSystemSeeder::class,
            SettingsSeeder::class,
        ]);

        // Depois cria/garante usuário admin
        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => '123456789',
                'email_verified_at' => now(),
            ]
        );

        // Vincula role Admin
        $user->assignRole('Admin');

        // Por fim, cria usuários judiciais de teste
        $this->call([
            \Database\Seeders\JudicialUsersSeeder::class,
        ]);
    }
}
