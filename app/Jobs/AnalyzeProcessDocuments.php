<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\EprocService;
use App\Services\PdfToTextService;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Filament\Notifications\Notification as FilamentNotification;

class AnalyzeProcessDocuments implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutos
    public int $tries = 2; // Tenta 2 vezes se falhar

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public string $numeroProcesso,
        public array $documentos,
        public array $contextoDados,
        public string $promptTemplate,
        public string $userLogin,
        public string $senha,
        public int $judicialUserId
    ) {}

    /**
     * Execute o job.
     */
    public function handle(): void
    {
        try {
            $user = User::find($this->userId);

            if (!$user) {
                Log::error('Usuário não encontrado', ['user_id' => $this->userId]);
                return;
            }

            $startTime = microtime(true);
            $pdfService = new PdfToTextService();
            $geminiService = new GeminiService();
            $eprocService = new EprocService($this->userLogin, $this->senha);

            // Array para armazenar documentos processados
            $documentosProcessados = [];
            $totalDocumentos = count($this->documentos);
            $processados = 0;

            // Notifica início
            $this->sendNotification(
                $user,
                'Análise Iniciada',
                "Processando {$totalDocumentos} documento(s) do processo {$this->numeroProcesso}",
                'info'
            );

            // Processa cada documento
            foreach ($this->documentos as $documento) {
                try {
                    $processados++;

                    // Busca o conteúdo do documento
                    $documentoCompleto = $this->fetchDocumento($eprocService, $documento['idDocumento']);

                    if (!$documentoCompleto || empty($documentoCompleto['conteudo'])) {
                        Log::warning('Documento sem conteúdo', [
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
                    $documentosProcessados[] = [
                        'descricao' => $documento['descricao'] ?? "Documento {$processados}",
                        'texto' => $texto,
                        'id_documento' => $documento['idDocumento'],
                        'dataHora' => $documento['dataHora'] ?? null,
                    ];

                    // Cria registro no banco para rastreamento
                    DocumentAnalysis::create([
                        'user_id' => $this->userId,
                        'numero_processo' => $this->numeroProcesso,
                        'id_documento' => $documento['idDocumento'],
                        'descricao_documento' => $documento['descricao'] ?? null,
                        'extracted_text' => $texto,
                        'status' => 'processing',
                        'total_characters' => mb_strlen($texto),
                    ]);

                    // Notifica progresso
                    if ($processados % 5 == 0 || $processados == $totalDocumentos) {
                        $this->sendNotification(
                            $user,
                            'Progresso',
                            "Processados {$processados} de {$totalDocumentos} documentos",
                            'info'
                        );
                    }

                } catch (\Exception $e) {
                    Log::error('Erro ao processar documento', [
                        'id_documento' => $documento['idDocumento'],
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            if (empty($documentosProcessados)) {
                $this->sendNotification(
                    $user,
                    'Análise Falhou',
                    'Nenhum documento pôde ser processado',
                    'danger'
                );
                return;
            }

            // Envia tudo para análise da IA em um único request (mais eficiente)
            $analiseCompleta = $geminiService->analyzeDocuments(
                $this->promptTemplate,
                $documentosProcessados,
                $this->contextoDados
            );

            $endTime = microtime(true);
            $processingTime = (int) (($endTime - $startTime) * 1000);

            // Atualiza todos os registros com a análise completa
            DocumentAnalysis::where('user_id', $this->userId)
                ->where('numero_processo', $this->numeroProcesso)
                ->where('status', 'processing')
                ->update([
                    'ai_analysis' => $analiseCompleta,
                    'status' => 'completed',
                    'processing_time_ms' => $processingTime,
                ]);

            // Notifica sucesso
            $this->sendNotification(
                $user,
                'Análise Concluída',
                "Análise de {$processados} documento(s) concluída com sucesso! Tempo: " . round($processingTime / 1000, 2) . "s",
                'success'
            );

        } catch (\Exception $e) {
            Log::error('Erro geral na análise de documentos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Marca como falho
            DocumentAnalysis::where('user_id', $this->userId)
                ->where('numero_processo', $this->numeroProcesso)
                ->where('status', 'processing')
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

            $this->sendNotification(
                User::find($this->userId),
                'Análise Falhou',
                'Erro: ' . $e->getMessage(),
                'danger'
            );

            throw $e;
        }
    }

    /**
     * Busca o documento completo do webservice
     */
    private function fetchDocumento(EprocService $eprocService, string $idDocumento): ?array
    {
        try {
            $resultado = $eprocService->consultarDocumentosProcesso(
                $this->numeroProcesso,
                [$idDocumento],
                true // incluir conteúdo
            );

            $documentos = $resultado['documento'] ?? [];

            if (empty($documentos)) {
                return null;
            }

            // Se retornou um único documento, não está em array
            if (isset($documentos['idDocumento'])) {
                $documentos = [$documentos];
            }

            foreach ($documentos as $doc) {
                if ($doc['idDocumento'] == $idDocumento) {
                    return [
                        'conteudo' => $doc['conteudo'] ?? null,
                        'descricao' => $doc['descricao'] ?? null,
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Erro ao buscar documento', [
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
}
