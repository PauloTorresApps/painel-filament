<?php

namespace Database\Seeders;

use App\Models\System;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $systems = [
            [
                'name' => 'EPROC',
                'description' => 'Sistema Eletrônico de Processos',
                'is_active' => true,
            ],
            [
                'name' => 'PJE',
                'description' => 'Processo Judicial Eletrônico',
                'is_active' => true,
            ],
            [
                'name' => 'PROJUDI',
                'description' => 'Processo Judicial Digital',
                'is_active' => true,
            ],
            [
                'name' => 'SEEU',
                'description' => 'Sistema Eletrônico de Execução Unificado',
                'is_active' => true,
            ],
        ];

        foreach ($systems as $system) {
            System::firstOrCreate(
                ['name' => $system['name']],
                $system
            );
        }
    }
}
