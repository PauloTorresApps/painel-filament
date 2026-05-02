<?php

namespace App\Console\Commands\ProcessAnalysis;

use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\System;
use Illuminate\Console\Command;
use RuntimeException;

class ImportRequiredPrompts extends Command
{
    protected $signature = 'analysis:import-required-prompts
                            {jsonPath : Caminho para arquivo JSON com prompts}
                            {--system=1 : ID do sistema judicial}
                            {--model-id= : ID do modelo de IA para associar aos prompts}
                            {--provider=openrouter : Provider padrao para prompts sem provider no JSON}';

    protected $description = 'Importa prompts obrigatorios do pipeline processual a partir de JSON externo';

    public function handle(): int
    {
        $jsonPath = (string) $this->argument('jsonPath');
        $systemId = (int) $this->option('system');
        $defaultProvider = (string) $this->option('provider');
        $forcedModelId = $this->option('model-id');

        if (!is_file($jsonPath)) {
            $this->error('Arquivo JSON nao encontrado: ' . $jsonPath);
            return self::FAILURE;
        }

        $system = System::find($systemId);
        if (!$system) {
            $this->error('Sistema nao encontrado para ID ' . $systemId . '.');
            return self::FAILURE;
        }

        $rawJson = file_get_contents($jsonPath);
        if ($rawJson === false) {
            $this->error('Falha ao ler arquivo JSON.');
            return self::FAILURE;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('JSON invalido: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!is_array($decoded)) {
            $this->error('Formato invalido: JSON deve ser um objeto com prompt_type como chave.');
            return self::FAILURE;
        }

        $missingTypes = [];
        $upserted = 0;

        foreach (AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES as $promptType) {
            $entry = $decoded[$promptType] ?? null;

            if (!is_array($entry)) {
                $missingTypes[] = $promptType;
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));
            $content = trim((string) ($entry['content'] ?? ''));

            if ($title === '' || $content === '') {
                $missingTypes[] = $promptType;
                continue;
            }

            $provider = (string) ($entry['ai_provider'] ?? $defaultProvider);
            $temperature = isset($entry['temperature']) ? (float) $entry['temperature'] : 0.3;
            $deepThinkingEnabled = array_key_exists('deep_thinking_enabled', $entry)
                ? (bool) $entry['deep_thinking_enabled']
                : true;
            $analysisStrategy = (string) ($entry['analysis_strategy'] ?? 'evolutionary');

            $modelId = null;

            if (!empty($forcedModelId)) {
                $modelId = (int) $forcedModelId;
            } elseif (!empty($entry['ai_model_id'])) {
                $modelId = (int) $entry['ai_model_id'];
            } elseif (!empty($entry['ai_model_key']) && is_string($entry['ai_model_key'])) {
                $parts = explode('|', $entry['ai_model_key'], 2);
                if (count($parts) === 2) {
                    $modelId = AiModel::query()
                        ->where('provider', $parts[0])
                        ->where('model_id', $parts[1])
                        ->value('id');
                }
            }

            if (empty($modelId)) {
                $modelId = AiModel::query()
                    ->where('provider', $provider)
                    ->where('is_active', true)
                    ->value('id');
            }

            if (empty($modelId)) {
                throw new RuntimeException('Nenhum modelo ativo encontrado para provider ' . $provider . ' ao importar tipo ' . $promptType . '.');
            }

            AiPrompt::query()->updateOrCreate(
                [
                    'system_id' => $systemId,
                    'prompt_type' => $promptType,
                ],
                [
                    'title' => $title,
                    'content' => $content,
                    'ai_provider' => $provider,
                    'ai_model_id' => $modelId,
                    'deep_thinking_enabled' => $deepThinkingEnabled,
                    'analysis_strategy' => $analysisStrategy,
                    'temperature' => $temperature,
                    'is_active' => true,
                    'is_default' => true,
                ]
            );

            $upserted++;
        }

        if (!empty($missingTypes)) {
            $this->error('JSON incompleto. Tipos obrigatorios ausentes/invalidos:');
            foreach ($missingTypes as $type) {
                $this->line('- ' . $type);
            }
            return self::FAILURE;
        }

        $this->info('Prompts importados com sucesso.');
        $this->line('Sistema: ' . $systemId);
        $this->line('Registros upserted: ' . $upserted);

        return self::SUCCESS;
    }
}
