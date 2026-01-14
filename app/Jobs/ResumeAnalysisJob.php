<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\EprocService;
use App\Services\PdfToTextService;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

class ResumeAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 600; // 10 minutos
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $analysisId
    ) {}

    /**
     * A chave única para este job (evita duplicação)
     */
    public function uniqueId(): string
    {
        return "resume_analysis_{$this->analysisId}";
    }

    /**
     * Tempo que o lock do unique job deve durar (em segundos)
     */
    public int $uniqueFor = 600;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $analysis = DocumentAnalysis::find($this->analysisId);

            if (!$analysis) {
                Log::error('Análise não encontrada para retomada', ['analysis_id' => $this->analysisId]);
                return;
            }

            if (!$analysis->canBeResumed()) {
                Log::warning('Análise não pode ser retomada', [
                    'analysis_id' => $this->analysisId,
                    'status' => $analysis->status,
                    'is_resumable' => $analysis->is_resumable,
                    'progress' => "{$analysis->processed_documents_count}/{$analysis->total_documents}"
                ]);
                return;
            }

            $user = User::find($analysis->user_id);

            if (!$user) {
                Log::error('Usuário não encontrado', ['user_id' => $analysis->user_id]);
                return;
            }

            Log::info('Iniciando retomada de análise', [
                'analysis_id' => $analysis->id,
                'numero_processo' => $analysis->numero_processo,
                'progress' => "{$analysis->processed_documents_count}/{$analysis->total_documents}",
                'percentage' => $analysis->getProgressPercentage() . '%'
            ]);

            // Notifica início da retomada
            $this->sendNotification(
                $user,
                'Retomada Iniciada',
                "Retomando análise do processo {$analysis->numero_processo} de onde parou ({$analysis->getProgressPercentage()}%)",
                'info'
            );

            // Recupera parâmetros do job original
            $jobParams = $analysis->job_parameters;

            if (!$jobParams) {
                throw new \Exception('Parâmetros do job original não encontrados');
            }

            $startTime = microtime(true);
            $pdfService = new PdfToTextService();
            $eprocService = new EprocService(
                $jobParams['userLogin'],
                $jobParams['senha']
            );

            // Instancia o serviço de IA
            $aiService = $this->getAIService($jobParams['aiProvider']);

            // Reprocessa os documentos que faltam
            $documentosOriginais = $jobParams['documentos'];
            $totalDocumentos = count($documentosOriginais);
            $documentosProcessados = [];
            $startIndex = $analysis->current_document_index + 1; // Próximo documento a processar

            Log::info('Reprocessando documentos restantes', [
                'start_index' => $startIndex,
                'total_documentos' => $totalDocumentos,
                'documentos_faltantes' => $totalDocumentos - $startIndex
            ]);

            // Processa apenas os documentos que faltam
            foreach ($documentosOriginais as $index => $documento) {
                try {
                    // Busca o conteúdo do documento
                    $documentoCompleto = $this->fetchDocumento($eprocService, $documento['idDocumento'], $analysis->numero_processo);

                    if (!$documentoCompleto || empty($documentoCompleto['conteudo'])) {
                        Log::warning('Documento sem conteúdo na retomada', [
                            'id_documento' => $documento['idDocumento']
                        ]);
                        continue;
                    }

                    // Extrai texto do PDF
                    $texto = $pdfService->extractText(
                        $documentoCompleto['conteudo'],
                        "doc_{$documento['idDocumento']}.pdf"
                    );

                    // Armazena informações do documento processado
                    $docData = [
                        'descricao' => $documento['descricao'] ?? "Documento " . ($index + 1),
                        'texto' => $texto,
                        'id_documento' => $documento['idDocumento'],
                        'dataHora' => $documento['dataHora'] ?? null,
                    ];

                    // Se for Gemini, inclui PDF base64
                    if ($jobParams['aiProvider'] === 'gemini') {
                        $docData['pdf_base64'] = $documentoCompleto['conteudo'];
                    }

                    $documentosProcessados[] = $docData;

                } catch (\Exception $e) {
                    Log::error('Erro ao reprocessar documento na retomada', [
                        'id_documento' => $documento['idDocumento'],
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            if (empty($documentosProcessados)) {
                $this->sendNotification(
                    $user,
                    'Retomada Falhou',
                    'Nenhum documento pôde ser reprocessado',
                    'danger'
                );
                return;
            }

            // Marca análise como em processamento novamente
            $analysis->update(['status' => 'processing']);

            // Continua a análise de onde parou
            // O GeminiService vai detectar que pode retomar e usar o resumo evolutivo existente
            Log::info('Continuando análise via ' . $aiService->getName(), [
                'provider' => $jobParams['aiProvider'],
                'deep_thinking_enabled' => $jobParams['deepThinkingEnabled'],
                'total_documentos' => count($documentosProcessados),
                'analysis_id' => $analysis->id,
                'retomando_de' => $startIndex
            ]);

            $analiseCompleta = $aiService->analyzeDocuments(
                $jobParams['promptTemplate'],
                $documentosProcessados,
                $jobParams['contextoDados'],
                $jobParams['deepThinkingEnabled'],
                $analysis // Passa o DocumentAnalysis para retomar
            );

            $endTime = microtime(true);
            $processingTime = (int) (($endTime - $startTime) * 1000);

            // Finaliza análise evolutiva com sucesso
            $analysis->finalizeEvolutionaryAnalysis($analiseCompleta, $processingTime);

            Log::info('Retomada concluída com sucesso', [
                'analysis_id' => $analysis->id,
                'processing_time_ms' => $processingTime
            ]);

            // Notifica sucesso
            $this->sendNotification(
                $user,
                'Retomada Concluída',
                "Análise do processo {$analysis->numero_processo} concluída com sucesso! Tempo: " . round($processingTime / 1000, 2) . "s",
                'success'
            );

        } catch (\Exception $e) {
            Log::error('Erro na retomada de análise', [
                'analysis_id' => $this->analysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Marca como falho novamente
            if (isset($analysis)) {
                $analysis->update([
                    'status' => 'failed',
                    'error_message' => 'Erro na retomada: ' . $e->getMessage(),
                ]);
            }

            $this->sendNotification(
                User::find($analysis->user_id ?? null),
                'Retomada Falhou',
                'Erro: ' . $e->getMessage(),
                'danger'
            );

            throw $e;
        }
    }

    /**
     * Busca o documento completo do webservice
     */
    private function fetchDocumento(EprocService $eprocService, string $idDocumento, string $numeroProcesso): ?array
    {
        try {
            $resultado = $eprocService->consultarDocumentosProcesso(
                $numeroProcesso,
                [$idDocumento],
                true
            );

            $documentos = null;

            if (isset($resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'])) {
                $documentos = $resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'];
            } elseif (isset($resultado['documento'])) {
                $documentos = $resultado['documento'];
            } else {
                return null;
            }

            if (empty($documentos)) {
                return null;
            }

            if (isset($documentos['idDocumento'])) {
                $documentos = [$documentos];
            }

            foreach ($documentos as $doc) {
                if ($doc['idDocumento'] == $idDocumento) {
                    $conteudoBase64 = null;

                    if (is_array($doc['conteudo'] ?? null) && isset($doc['conteudo']['conteudo'])) {
                        $conteudoBase64 = $doc['conteudo']['conteudo'];
                    } elseif (is_string($doc['conteudo'] ?? null)) {
                        $conteudoBase64 = $doc['conteudo'];
                    }

                    return [
                        'conteudo' => $conteudoBase64,
                        'descricao' => $doc['descricao'] ?? null,
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Erro ao buscar documento na retomada', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Envia notificação para o usuário via Filament
     */
    private function sendNotification(?User $user, string $title, string $body, string $status = 'info'): void
    {
        if (!$user) {
            return;
        }

        FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->status($status)
            ->sendToDatabase($user);
    }

    /**
     * Retorna o serviço de IA baseado no provider selecionado
     */
    private function getAIService(string $provider): AIProviderInterface
    {
        return match($provider) {
            'deepseek' => new DeepSeekService(),
            'gemini' => new GeminiService(),
            'openai' => new \App\Services\OpenAIService(),
            default => throw new \Exception("Provider de IA '{$provider}' não suportado. Use 'gemini', 'deepseek' ou 'openai'.")
        };
    }
}
