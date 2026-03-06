<?php

namespace Database\Seeders;

use App\Models\JudicialUser;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Seeder;

class JudicialUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $system = System::firstOrCreate(
            ['name' => 'EPROC'],
            [
                'description' => 'Sistema Eletrônico de Processos',
                'is_active' => true,
            ]
        );

        $users = [
            [
                'name' => 'Analista Processo 01',
                'email' => 'analista.processo1@admin.com',
                'password' => '123456789',
                'judicial_login' => 'analista.processo1',
                'is_default' => true,
            ],
            [
                'name' => 'Analista Processo 02',
                'email' => 'analista.processo2@admin.com',
                'password' => '123456789',
                'judicial_login' => 'analista.processo2',
                'is_default' => true,
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $userData['password'],
                    'email_verified_at' => now(),
                ]
            );

            $user->assignRole('Analista de Processo');

            JudicialUser::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'system_id' => $system->id,
                ],
                [
                    'user_login' => $userData['judicial_login'],
                    'is_default' => $userData['is_default'],
                ]
            );
        }
    }
}
