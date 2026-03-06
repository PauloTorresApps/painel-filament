<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use App\Models\System;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class ContractSystemSeeder extends Seeder
{
    private const PROMPT_PLACEHOLDER = 'Adicione o seu prompt';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Criar role "Analista de Contrato"
        $role = Role::firstOrCreate(
            ['name' => 'Analista de Contrato'],
            ['guard_name' => 'web']
        );

        $this->command->info("Role 'Analista de Contrato' criada/verificada.");

        // 2. Criar System "Contratos"
        $system = System::firstOrCreate(
            ['name' => 'Contratos'],
            [
                'description' => 'Sistema de análise de contratos',
                'is_active' => true,
            ]
        );

        $this->command->info("System 'Contratos' criado/verificado (ID: {$system->id}).");

        // 3. Criar AiPrompt padrão para ANÁLISE de contratos
        // Análise básica não precisa de deep thinking (mais rápido)
        $analysisPrompt = AiPrompt::updateOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_ANALYSIS,
                'is_default' => true,
            ],
            [
                'title' => 'Análise de Contratos',
                'content' => self::PROMPT_PLACEHOLDER,
                'ai_provider' => 'openrouter',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para ANÁLISE de contratos criado/verificado (ID: {$analysisPrompt->id}).");

        // 4. Criar AiPrompt padrão para PARECER JURÍDICO
        // Parecer jurídico requer análise mais profunda - deep thinking habilitado
        $legalOpinionPrompt = AiPrompt::updateOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_LEGAL_OPINION,
                'is_default' => true,
            ],
            [
                'title' => 'Parecer Jurídico',
                'content' => self::PROMPT_PLACEHOLDER,
                'ai_provider' => 'openrouter',
                'deep_thinking_enabled' => true,  // Parecer jurídico usa pensamento profundo
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para PARECER JURÍDICO criado/verificado (ID: {$legalOpinionPrompt->id}).");

        // 5. Criar AiPrompt padrão para STORYBOARD (JSON do infográfico)
        $storyboardPrompt = AiPrompt::updateOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_STORYBOARD,
                'is_default' => true,
            ],
            [
                'title' => 'Storyboard de Infográfico (JSON)',
                'content' => self::PROMPT_PLACEHOLDER,
                'ai_provider' => 'openrouter',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para STORYBOARD criado/verificado (ID: {$storyboardPrompt->id}).");

        // 6. Criar AiPrompt padrão para INFOGRÁFICO (HTML)
        $infographicPrompt = AiPrompt::updateOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_INFOGRAPHIC,
                'is_default' => true,
            ],
            [
                'title' => 'Infográfico HTML',
                'content' => self::PROMPT_PLACEHOLDER,
                'ai_provider' => 'openrouter',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para INFOGRÁFICO HTML criado/verificado (ID: {$infographicPrompt->id}).");
    }
}
