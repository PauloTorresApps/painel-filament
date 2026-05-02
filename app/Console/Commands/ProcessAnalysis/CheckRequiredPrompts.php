<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Models\AiPrompt;
use Illuminate\Console\Command;

class CheckRequiredPrompts extends Command
{
    protected $signature = 'analysis:check-required-prompts
                            {--system=1 : ID do sistema judicial para validar}';

    protected $description = 'Valida se todos os prompts obrigatorios do pipeline processual estao ativos e marcados como padrao';

    public function handle(): int
    {
        $systemId = (int) $this->option('system');

        $requiredTypes = AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES;
        $allLabels = AiPrompt::getAllPromptTypes();

        $availableTypes = AiPrompt::query()
            ->where('system_id', $systemId)
            ->whereIn('prompt_type', $requiredTypes)
            ->where('is_active', true)
            ->where('is_default', true)
            ->pluck('prompt_type')
            ->filter()
            ->values()
            ->all();

        $rows = [];
        foreach ($requiredTypes as $type) {
            $rows[] = [
                $type,
                $allLabels[$type] ?? $type,
                in_array($type, $availableTypes, true) ? 'OK' : 'MISSING',
            ];
        }

        $this->table(['Prompt Type', 'Finalidade', 'Status'], $rows);

        $missing = AiPrompt::getMissingRequiredJudicialPromptTypes($systemId);

        if (!empty($missing)) {
            $this->error('Prompts obrigatorios faltando para o sistema ' . $systemId . '.');

            foreach ($missing as $type => $label) {
                $this->line('- ' . $label . ' (' . $type . ')');
            }

            return self::FAILURE;
        }

        $this->info('Todos os prompts obrigatorios estao configurados para o sistema ' . $systemId . '.');

        return self::SUCCESS;
    }
}
