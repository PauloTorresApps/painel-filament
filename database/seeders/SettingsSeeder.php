<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::updateOrCreate(
            ['key' => 'debug_save_analysis_files'],
            [
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'debug',
                'description' => 'Quando ativado, salva arquivos de debug (.md) com os resultados das análises de documentos (MAP), consolidações (REDUCE) e parecer final no diretório storage/app/private/analises-debug/',
            ]
        );
    }
}
