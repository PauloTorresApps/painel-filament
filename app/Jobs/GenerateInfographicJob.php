<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\ContractAnalysis;
use App\Models\System;
use App\Models\User;
use App\Services\DeepSeekService;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use App\Contracts\AIProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

class GenerateInfographicJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 0; // Sem timeout - permite processamentos longos
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $contractAnalysisId
    ) {}

    /**
     * Chave única para evitar duplicação
     */
    public function uniqueId(): string
    {
        return "infographic_{$this->contractAnalysisId}";
    }

    public int $uniqueFor = 600; // 10 minutos

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $accumulatedMetadata = [];

        try {
            // Busca a análise
            $analysis = ContractAnalysis::find($this->contractAnalysisId);

            if (!$analysis) {
                Log::error('ContractAnalysis não encontrada para infográfico', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se o parecer jurídico está concluído
            if (!$analysis->isLegalOpinionCompleted()) {
                Log::warning('Parecer jurídico não está concluído', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se foi cancelado
            if ($analysis->isInfographicCancelled()) {
                Log::info('Infográfico foi cancelado', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se já está gerando infográfico
            if ($analysis->isInfographicProcessing()) {
                Log::warning('Infográfico já está sendo gerado', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Marca como processando
            $analysis->markInfographicAsProcessing();

            $user = User::find($analysis->user_id);
            if (!$user) {
                throw new \Exception('Usuário não encontrado');
            }

            // Notifica início do processamento
            $this->sendNotification(
                $user,
                'Gerando Infográfico',
                "O infográfico para o contrato '{$analysis->file_name}' está sendo gerado.",
                'info'
            );

            Log::info('Iniciando geração de infográfico', [
                'id' => $analysis->id,
                'file_name' => $analysis->file_name
            ]);

            // Busca o sistema de Contratos
            $system = System::where('name', 'Contratos')->first();

            if (!$system) {
                throw new \Exception('Sistema "Contratos" não encontrado.');
            }

            // FASE 1: Busca prompt do Storyboard (JSON)
            $storyboardPrompt = AiPrompt::where('system_id', $system->id)
                ->where('prompt_type', AiPrompt::TYPE_STORYBOARD)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$storyboardPrompt) {
                throw new \Exception('Nenhum prompt padrão ativo encontrado para storyboard (tipo: storyboard).');
            }

            // FASE 2: Busca prompt do Infográfico (HTML)
            $infographicPrompt = AiPrompt::where('system_id', $system->id)
                ->where('prompt_type', AiPrompt::TYPE_INFOGRAPHIC)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$infographicPrompt) {
                throw new \Exception('Nenhum prompt padrão ativo encontrado para infográfico HTML (tipo: infographic).');
            }

            // Atualiza análise com informações dos prompts
            $analysis->update([
                'infographic_storyboard_prompt_id' => $storyboardPrompt->id,
                'infographic_html_prompt_id' => $infographicPrompt->id,
            ]);

            // Verifica novamente se foi cancelado antes da Fase 1
            $analysis->refresh();
            if ($analysis->isInfographicCancelled()) {
                Log::info('Infográfico foi cancelado antes da Fase 1', ['id' => $this->contractAnalysisId]);
                return;
            }

            // ========== FASE 1: Gerar Storyboard JSON ==========
            Log::info('Fase 1: Gerando storyboard JSON', ['id' => $analysis->id]);

            $storyboardAiService = $this->getAIService($storyboardPrompt->ai_provider);

            if ($storyboardPrompt->aiModel && !empty($storyboardPrompt->aiModel->model_id)) {
                $storyboardAiService->setModel($storyboardPrompt->aiModel->model_id);
            }

            // Prepara o documento com o parecer jurídico
            $documentos = [
                [
                    'descricao' => "Parecer Jurídico: {$analysis->file_name}",
                    'texto' => $analysis->legal_opinion_result,
                ]
            ];

            // Contexto para o storyboard
            $contextoDados = [
                'tipo' => 'Infográfico Visual Law',
                'arquivo_original' => $analysis->file_name,
                'data_parecer' => $analysis->updated_at->format('d/m/Y H:i'),
            ];

            if (!empty($analysis->interested_party_name)) {
                $contextoDados['parte_interessada'] = $analysis->interested_party_name;
            }

            Log::info('Gerando storyboard JSON com IA', [
                'id' => $analysis->id,
                'provider' => $storyboardPrompt->ai_provider,
                'model' => $storyboardAiService->getModel(),
            ]);

            // Executa geração do storyboard
            $storyboardResult = $storyboardAiService->analyzeDocuments(
                $storyboardPrompt->content,
                $documentos,
                $contextoDados,
                $storyboardPrompt->deep_thinking_enabled
            );

            // Captura metadados da Fase 1
            $phase1Metadata = $storyboardAiService->getLastAnalysisMetadata();
            $accumulatedMetadata['phase1_storyboard'] = $phase1Metadata;

            // Extrai e valida o JSON
            $storyboardJson = $this->extractAndValidateJson($storyboardResult);

            Log::info('Fase 1 concluída: Storyboard JSON gerado', [
                'id' => $analysis->id,
                'json_length' => strlen($storyboardJson)
            ]);

            // Verifica se foi cancelado antes da Fase 2
            $analysis->refresh();
            if ($analysis->isInfographicCancelled()) {
                Log::info('Infográfico foi cancelado antes da Fase 2', ['id' => $this->contractAnalysisId]);
                return;
            }

            // ========== FASE 2: Gerar HTML do Infográfico ==========
            Log::info('Fase 2: Gerando HTML do infográfico', ['id' => $analysis->id]);

            $htmlAiService = $this->getAIService($infographicPrompt->ai_provider);

            if ($infographicPrompt->aiModel && !empty($infographicPrompt->aiModel->model_id)) {
                $htmlAiService->setModel($infographicPrompt->aiModel->model_id);
            }

            // Prepara o documento com o JSON do storyboard
            $htmlDocumentos = [
                [
                    'descricao' => 'Storyboard JSON para infográfico',
                    'texto' => $storyboardJson,
                ]
            ];

            Log::info('Gerando HTML do infográfico com IA', [
                'id' => $analysis->id,
                'provider' => $infographicPrompt->ai_provider,
                'model' => $htmlAiService->getModel(),
            ]);

            // Executa geração do HTML
            $htmlResult = $htmlAiService->analyzeDocuments(
                $infographicPrompt->content,
                $htmlDocumentos,
                $contextoDados,
                $infographicPrompt->deep_thinking_enabled
            );

            // Captura metadados da Fase 2
            $phase2Metadata = $htmlAiService->getLastAnalysisMetadata();
            $accumulatedMetadata['phase2_html'] = $phase2Metadata;

            // Acumula totais
            $accumulatedMetadata['totals'] = [
                'total_prompt_tokens' => ($phase1Metadata['total_prompt_tokens'] ?? 0) + ($phase2Metadata['total_prompt_tokens'] ?? 0),
                'total_completion_tokens' => ($phase1Metadata['total_completion_tokens'] ?? 0) + ($phase2Metadata['total_completion_tokens'] ?? 0),
                'total_tokens' => ($phase1Metadata['total_tokens'] ?? 0) + ($phase2Metadata['total_tokens'] ?? 0),
                'api_calls_count' => ($phase1Metadata['api_calls_count'] ?? 0) + ($phase2Metadata['api_calls_count'] ?? 0),
            ];

            // Extrai o HTML da resposta
            $htmlContent = $this->extractHtml($htmlResult);

            // Verifica se foi cancelado durante o processamento
            $analysis->refresh();
            if ($analysis->isInfographicCancelled()) {
                Log::info('Infográfico foi cancelado durante processamento', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Calcula tempo de processamento
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Marca como concluído com metadados
            $analysis->markInfographicAsCompleted($storyboardJson, $htmlContent, $processingTimeMs, $accumulatedMetadata);

            Log::info('Infográfico gerado com sucesso', [
                'id' => $analysis->id,
                'processing_time_ms' => $processingTimeMs
            ]);

            // Notifica o usuário
            $this->sendNotification(
                $user,
                'Infográfico Concluído',
                "O infográfico para o contrato '{$analysis->file_name}' foi gerado com sucesso.",
                'success'
            );

        } catch (\Exception $e) {
            Log::error('Erro ao gerar infográfico', [
                'id' => $this->contractAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Tenta atualizar o status para failed
            if (isset($analysis)) {
                $analysis->markInfographicAsFailed($e->getMessage());

                if (isset($user)) {
                    $this->sendNotification(
                        $user,
                        'Erro ao Gerar Infográfico',
                        "Ocorreu um erro ao gerar o infográfico: {$e->getMessage()}",
                        'danger'
                    );
                }
            }
        }
    }

    /**
     * Obtém o serviço de IA apropriado
     */
    private function getAIService(string $provider): AIProviderInterface
    {
        return match ($provider) {
            'gemini' => new GeminiService(),
            'openai' => new OpenAIService(),
            'deepseek' => new DeepSeekService(),
            default => new GeminiService(),
        };
    }

    /**
     * Extrai e valida JSON da resposta da IA
     */
    private function extractAndValidateJson(string $response): string
    {
        // Tenta extrair JSON de blocos de código markdown
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $jsonStr = trim($matches[1]);
        } else {
            $jsonStr = trim($response);
        }

        // Valida o JSON
        $decoded = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON inválido retornado pela IA: ' . json_last_error_msg());
        }

        return $jsonStr;
    }

    /**
     * Extrai HTML da resposta da IA
     */
    private function extractHtml(string $response): string
    {
        // Tenta extrair HTML de blocos de código markdown
        if (preg_match('/```(?:html)?\s*([\s\S]*?)```/', $response, $matches)) {
            return trim($matches[1]);
        }

        // Se não houver bloco de código, verifica se começa com DOCTYPE ou html
        $trimmed = trim($response);
        if (stripos($trimmed, '<!DOCTYPE') === 0 || stripos($trimmed, '<html') === 0) {
            return $trimmed;
        }

        // Último recurso: retorna como está
        return $trimmed;
    }

    /**
     * Envia notificação para o usuário
     */
    private function sendNotification(User $user, string $title, string $body, string $status): void
    {
        try {
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->status($status)
                ->sendToDatabase($user);
        } catch (\Exception $e) {
            Log::warning('Erro ao enviar notificação', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
