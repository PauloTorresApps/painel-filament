<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\System;
use Illuminate\Database\Seeder;
use RuntimeException;

class RequiredJudicialPromptsSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('prompts/required_judicial_prompts.json');

        if (!is_file($jsonPath)) {
            throw new RuntimeException('Arquivo de prompts nao encontrado: ' . $jsonPath);
        }

        $rawJson = file_get_contents($jsonPath);
        if ($rawJson === false) {
            throw new RuntimeException('Falha ao ler arquivo de prompts: ' . $jsonPath);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('JSON de prompts invalido: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Formato invalido: JSON de prompts deve ser um objeto com prompt_type como chave.');
        }

        $systemId = $this->resolveSystemId();
        $defaultProvider = 'openrouter';

        foreach (AiPrompt::REQUIRED_JUDICIAL_PROMPT_TYPES as $promptType) {
            $entry = $decoded[$promptType] ?? null;

            if (!is_array($entry)) {
                throw new RuntimeException('Prompt obrigatorio ausente no JSON: ' . $promptType);
            }

            $title = trim((string) ($entry['title'] ?? ''));
            $content = $this->normalizePromptContent($entry['content'] ?? null, $promptType);

            if ($title === '' || $content === '') {
                throw new RuntimeException('Prompt obrigatorio com titulo/conteudo vazio: ' . $promptType);
            }

            $provider = (string) ($entry['ai_provider'] ?? $defaultProvider);
            $temperature = isset($entry['temperature']) ? (float) $entry['temperature'] : 0.3;
            $deepThinkingEnabled = array_key_exists('deep_thinking_enabled', $entry)
                ? (bool) $entry['deep_thinking_enabled']
                : true;
            $analysisStrategy = (string) ($entry['analysis_strategy'] ?? 'evolutionary');

            $modelId = $this->resolveModelId($entry, $provider);

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
        }
    }

    /**
     * @param mixed $rawContent
     */
    private function normalizePromptContent(mixed $rawContent, string $promptType): string
    {
        if (is_string($rawContent)) {
            return trim($rawContent);
        }

        if (is_array($rawContent)) {
            try {
                $encoded = json_encode($rawContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException('Falha ao serializar content JSON do prompt: ' . $promptType, previous: $e);
            }

            return trim($encoded);
        }

        if (is_scalar($rawContent)) {
            return trim((string) $rawContent);
        }

        throw new RuntimeException('Campo content invalido para prompt: ' . $promptType);
    }

    private function resolveSystemId(): int
    {
        $system = System::query()->where('id', 1)->first();

        if ($system) {
            return 1;
        }

        $fallback = System::query()->orderBy('id')->first();

        if (!$fallback) {
            throw new RuntimeException('Nenhum sistema encontrado para associar prompts judiciais.');
        }

        return (int) $fallback->id;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveModelId(array $entry, string $provider): int
    {
        if (!empty($entry['ai_model_id'])) {
            $model = AiModel::query()->find((int) $entry['ai_model_id']);
            if ($model) {
                return (int) $model->id;
            }
        }

        if (!empty($entry['ai_model_key']) && is_string($entry['ai_model_key'])) {
            $parts = explode('|', $entry['ai_model_key'], 2);
            if (count($parts) === 2) {
                $modelId = AiModel::query()
                    ->where('provider', $parts[0])
                    ->where('model_id', $parts[1])
                    ->value('id');

                if ($modelId) {
                    return (int) $modelId;
                }
            }
        }

        $activeModelId = AiModel::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->value('id');

        if ($activeModelId) {
            return (int) $activeModelId;
        }

        throw new RuntimeException('Nenhum modelo ativo encontrado para provider ' . $provider . '.');
    }
}
