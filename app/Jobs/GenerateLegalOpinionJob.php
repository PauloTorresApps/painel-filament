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

class GenerateLegalOpinionJob implements ShouldQueue, ShouldBeUnique
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
        return "legal_opinion_{$this->contractAnalysisId}";
    }

    public int $uniqueFor = 600; // 10 minutos

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            // Busca a análise
            $analysis = ContractAnalysis::find($this->contractAnalysisId);

            if (!$analysis) {
                Log::error('ContractAnalysis não encontrada para parecer jurídico', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se a análise está concluída
            if (!$analysis->isCompleted()) {
                Log::warning('Análise de contrato não está concluída', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se já está gerando parecer
            if ($analysis->isLegalOpinionProcessing()) {
                Log::warning('Parecer jurídico já está sendo gerado', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Marca como processando
            $analysis->markLegalOpinionAsProcessing();

            $user = User::find($analysis->user_id);
            if (!$user) {
                throw new \Exception('Usuário não encontrado');
            }

            // Notifica início do processamento
            $this->sendNotification(
                $user,
                'Gerando Parecer Jurídico',
                "O parecer jurídico para o contrato '{$analysis->file_name}' está sendo gerado.",
                'info'
            );

            Log::info('Iniciando geração de parecer jurídico', [
                'id' => $analysis->id,
                'file_name' => $analysis->file_name
            ]);

            // Busca o prompt padrão para parecer jurídico
            $system = System::where('name', 'Contratos')->first();

            if (!$system) {
                throw new \Exception('Sistema "Contratos" não encontrado.');
            }

            $prompt = AiPrompt::where('system_id', $system->id)
                ->where('prompt_type', AiPrompt::TYPE_LEGAL_OPINION)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$prompt) {
                throw new \Exception('Nenhum prompt padrão ativo encontrado para parecer jurídico (tipo: legal_opinion).');
            }

            // Atualiza análise com informações do prompt
            $analysis->update([
                'legal_opinion_prompt_id' => $prompt->id,
                'legal_opinion_ai_provider' => $prompt->ai_provider,
            ]);

            // Prepara o documento com a análise prévia
            $documentos = [
                [
                    'descricao' => "Análise do contrato: {$analysis->file_name}",
                    'texto' => $analysis->analysis_result,
                ]
            ];

            // Contexto para o parecer jurídico
            $contextoDados = [
                'tipo' => 'Parecer Jurídico',
                'arquivo_original' => $analysis->file_name,
                'data_analise' => $analysis->updated_at->format('d/m/Y H:i'),
            ];

            // Adiciona nome da parte interessada ao contexto, se informado
            if (!empty($analysis->interested_party_name)) {
                $contextoDados['parte_interessada'] = $analysis->interested_party_name;
            }

            // Obtém o serviço de IA apropriado
            $aiService = $this->getAIService($prompt->ai_provider);

            Log::info('Gerando parecer jurídico com IA', [
                'id' => $analysis->id,
                'provider' => $prompt->ai_provider,
                'deep_thinking' => $prompt->deep_thinking_enabled
            ]);

            // Executa geração do parecer
            $result = $aiService->analyzeDocuments(
                $prompt->content,
                $documentos,
                $contextoDados,
                $prompt->deep_thinking_enabled
            );

            // Captura metadados da IA
            $aiMetadata = $aiService->getLastAnalysisMetadata();

            // Calcula tempo de processamento
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Marca como concluído com metadados
            $analysis->markLegalOpinionAsCompleted($result, $processingTimeMs, $aiMetadata);

            Log::info('Parecer jurídico gerado com sucesso', [
                'id' => $analysis->id,
                'processing_time_ms' => $processingTimeMs
            ]);

            // Notifica o usuário
            $this->sendNotification(
                $user,
                'Parecer Jurídico Concluído',
                "O parecer jurídico para o contrato '{$analysis->file_name}' foi gerado com sucesso.",
                'success'
            );

        } catch (\Exception $e) {
            Log::error('Erro ao gerar parecer jurídico', [
                'id' => $this->contractAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Tenta atualizar o status para failed
            if (isset($analysis)) {
                $analysis->markLegalOpinionAsFailed($e->getMessage());

                if (isset($user)) {
                    $this->sendNotification(
                        $user,
                        'Erro ao Gerar Parecer Jurídico',
                        "Ocorreu um erro ao gerar o parecer: {$e->getMessage()}",
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
