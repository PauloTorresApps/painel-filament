<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\ContractAnalysis;
use App\Models\System;
use App\Models\User;
use App\Services\DeepSeekService;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use App\Services\PdfToTextService;
use App\Contracts\AIProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification as FilamentNotification;

class AnalyzeContractJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 0; // Sem timeout - permite análises longas
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
        return "analyze_contract_{$this->contractAnalysisId}";
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
                Log::error('ContractAnalysis não encontrada', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Verifica se já está em processamento
            if ($analysis->isProcessing()) {
                Log::warning('Análise de contrato já em processamento', ['id' => $this->contractAnalysisId]);
                return;
            }

            // Marca como processando
            $analysis->markAsProcessing();

            $user = User::find($analysis->user_id);
            if (!$user) {
                throw new \Exception('Usuário não encontrado');
            }

            // Notifica início do processamento
            $this->sendNotification(
                $user,
                'Análise de Contrato Iniciada',
                "O contrato '{$analysis->file_name}' está sendo analisado pela IA.",
                'info'
            );

            // Extrai texto do PDF
            Log::info('Extraindo texto do contrato', [
                'id' => $analysis->id,
                'file_path' => $analysis->file_path
            ]);

            $pdfService = new PdfToTextService();
            $fullPath = Storage::path($analysis->file_path);

            if (!file_exists($fullPath)) {
                throw new \Exception("Arquivo não encontrado: {$analysis->file_path}");
            }

            $contractText = $pdfService->extractTextFromPath($fullPath);

            if (empty(trim($contractText))) {
                throw new \Exception('Não foi possível extrair texto do PDF. O arquivo pode estar protegido ou ser uma imagem.');
            }

            Log::info('Texto extraído do contrato', [
                'id' => $analysis->id,
                'chars' => strlen($contractText)
            ]);

            // Busca o prompt padrão para análise de contratos
            $system = System::where('name', 'Contratos')->first();

            if (!$system) {
                throw new \Exception('Sistema "Contratos" não encontrado. Execute o seeder ContractSystemSeeder.');
            }

            $prompt = AiPrompt::where('system_id', $system->id)
                ->where('prompt_type', AiPrompt::TYPE_ANALYSIS)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$prompt) {
                throw new \Exception('Nenhum prompt padrão ativo encontrado para análise de contratos (tipo: analysis).');
            }

            // Atualiza análise com informações do prompt
            $analysis->update([
                'prompt_id' => $prompt->id,
                'ai_provider' => $prompt->ai_provider,
            ]);

            // Prepara o documento para análise
            $documentos = [
                [
                    'descricao' => $analysis->file_name,
                    'texto' => $contractText,
                ]
            ];

            // Contexto básico do contrato
            $contextoDados = [
                'tipo' => 'Contrato',
                'arquivo' => $analysis->file_name,
                'tamanho' => $analysis->formatted_file_size,
            ];

            // Adiciona nome da parte interessada ao contexto, se informado
            if (!empty($analysis->interested_party_name)) {
                $contextoDados['parte_interessada'] = $analysis->interested_party_name;
            }

            // Obtém o serviço de IA apropriado
            $aiService = $this->getAIService($prompt->ai_provider);

            Log::info('Iniciando análise do contrato com IA', [
                'id' => $analysis->id,
                'provider' => $prompt->ai_provider,
                'deep_thinking' => $prompt->deep_thinking_enabled
            ]);

            // Executa análise
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

            // Marca como concluída com metadados
            $analysis->markAsCompleted($result, $processingTimeMs, $aiMetadata);

            // Remove o arquivo do storage após análise concluída
            $this->deleteContractFile($analysis);

            Log::info('Análise de contrato concluída', [
                'id' => $analysis->id,
                'processing_time_ms' => $processingTimeMs
            ]);

            // Notifica o usuário
            $this->sendNotification(
                $user,
                'Análise de Contrato Concluída',
                "A análise do contrato '{$analysis->file_name}' foi concluída com sucesso.",
                'success'
            );

        } catch (\Exception $e) {
            Log::error('Erro na análise de contrato', [
                'id' => $this->contractAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Tenta atualizar o status para failed
            if (isset($analysis)) {
                $analysis->markAsFailed($e->getMessage());

                // Remove o arquivo mesmo em caso de falha
                $this->deleteContractFile($analysis);

                if (isset($user)) {
                    $this->sendNotification(
                        $user,
                        'Erro na Análise de Contrato',
                        "Ocorreu um erro ao analisar o contrato: {$e->getMessage()}",
                        'danger'
                    );
                }
            }
        }
    }

    /**
     * Remove o arquivo de contrato do storage
     */
    private function deleteContractFile(ContractAnalysis $analysis): void
    {
        try {
            if ($analysis->file_path && Storage::exists($analysis->file_path)) {
                Storage::delete($analysis->file_path);

                // Limpa o path no registro
                $analysis->update(['file_path' => null]);

                Log::info('Arquivo de contrato removido do storage', [
                    'analysis_id' => $analysis->id,
                    'file_name' => $analysis->file_name
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao remover arquivo de contrato', [
                'analysis_id' => $analysis->id,
                'error' => $e->getMessage()
            ]);
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
