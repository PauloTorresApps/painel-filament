<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Pipeline\Graph\GraphRunner;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\Nodes\InventoryNode;
use App\Models\User;
use App\Services\ProcessAnalysis\EprocService;
use App\Services\HtmlToPdfService;
use App\Services\HtmlToTextService;
use App\Services\NotificationService;
use App\Services\OcrService;
use App\Services\PdfToTextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job orquestrador para análise de documentos de processo via map-reduce.
 *
 * Responsabilidades:
 * 1. Criar registro principal de análise
 * 2. Baixar TODOS os documentos em uma única chamada SOAP ao e-Proc
 * 3. Processar cada documento (extração de texto, salvamento em disco)
 * 4. Disparar fase MAP para processamento paralelo com IA
 *
 * Arquitetura:
 * - DOWNLOAD: chamada única ao e-Proc (síncrono neste job)
 * - MAP: Bus::batch() com MapDocumentAnalysisJob (paralelo)
 * - REDUCE: Bus::batch() com ReduceBatchJob (paralelo por nível)
 */
class AnalyzeProcessDocumentsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $uniqueFor;

    public function __construct(
        public int $userId,
        public string $numeroProcesso,
        public array $documentos,
        public array $contextoDados,
        public string $promptTemplate,              // Prompt para parecer final (REDUCE)
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $userLogin,
        public string $senha,
        public int $judicialUserId,
        public string $analysisStrategy = 'evolutionary',
        public ?string $aiModelId = null,                 // Modelo para REDUCE (parecer final)
        public ?string $documentAnalysisPrompt = null, // Prompt customizado para análise de documentos (MAP)
        public ?string $chave = null,                  // Chave do processo (para processos sigilosos)
        public ?string $mapModelId = null,             // Modelo para MAP (análise de documentos)
        public ?int $documentAnalysisId = null,        // Registro pré-criado para acompanhamento imediato
        public ?string $parteRepresentada = null,
        public ?string $papelProcessual = null,
        public ?string $objetivoAnalise = null,
        public ?string $prazoEmCurso = null
    ) {
        $this->timeout = config('analysis.jobs.analyze_process.timeout', 1800);
        $this->tries = config('analysis.jobs.analyze_process.tries', 2);
        $this->uniqueFor = config('analysis.jobs.analyze_process.unique_for', 600);
    }

    /**
     * Chave única para evitar duplicação
     */
    public function uniqueId(): string
    {
        return "analyze_process_{$this->userId}_{$this->numeroProcesso}";
    }

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    /**
     * Execute o job.
     */
    public function handle(): void
    {
        try {
            $documentAnalysis = null;

            if ($this->documentAnalysisId !== null) {
                $documentAnalysis = DocumentAnalysis::where('id', $this->documentAnalysisId)
                    ->where('user_id', $this->userId)
                    ->first();

                if (!$documentAnalysis) {
                    Log::error('AnalyzeProcessDocumentsJob: Análise pré-criada não encontrada', [
                        'document_analysis_id' => $this->documentAnalysisId,
                        'user_id' => $this->userId,
                    ]);
                    return;
                }
            }

            // Proteção contra duplicação
            $analiseEmAndamento = DocumentAnalysis::where('user_id', $this->userId)
                ->where('numero_processo', $this->numeroProcesso)
                ->where('status', 'processing')
                ->when($this->documentAnalysisId !== null, function ($query) {
                    $query->where('id', '!=', $this->documentAnalysisId);
                })
                ->first();

            if ($analiseEmAndamento) {
                Log::warning('AnalyzeProcessDocumentsJob: Análise duplicada bloqueada', [
                    'user_id' => $this->userId,
                    'numero_processo' => $this->numeroProcesso,
                    'analise_existente_id' => $analiseEmAndamento->id,
                ]);
                return;
            }

            $user = User::find($this->userId);
            if (!$user) {
                Log::error('AnalyzeProcessDocumentsJob: Usuário não encontrado', ['user_id' => $this->userId]);
                return;
            }

            $totalDocs = count($this->documentos);

            Log::info('AnalyzeProcessDocumentsJob: Iniciando processamento', [
                'numero_processo' => $this->numeroProcesso,
                'total_documentos' => $totalDocs,
            ]);

            // Notifica início do download
            $this->sendNotification(
                $user,
                'Baixando Documentos',
                "Baixando {$totalDocs} documento(s) do e-Proc para o processo {$this->numeroProcesso}.",
                'info'
            );

            // Formata classe e assuntos
            $classeProcessual = $this->contextoDados['classeProcessualNome']
                ?? $this->contextoDados['classeProcessual']
                ?? null;
            $assuntos = $this->formatAssuntosString($this->contextoDados['assunto'] ?? []);

            // Cria registro principal da análise quando não houver registro pré-criado.
            if (!$documentAnalysis) {
                $documentAnalysis = DocumentAnalysis::create([
                    'user_id' => $this->userId,
                    'numero_processo' => $this->numeroProcesso,
                    'parte_representada' => $this->parteRepresentada,
                    'papel_processual' => $this->papelProcessual,
                    'objetivo_analise' => $this->objetivoAnalise,
                    'prazo_em_curso' => $this->prazoEmCurso,
                    'classe_processual' => $classeProcessual,
                    'assuntos' => $assuntos,
                    'descricao_documento' => $totalDocs . ' documento(s) do processo',
                    'status' => 'processing',
                    'total_documents' => $totalDocs,
                    'job_parameters' => [
                        'documentos' => $this->documentos,
                        'contextoDados' => $this->contextoDados,
                        'promptTemplate' => $this->promptTemplate,
                        'documentAnalysisPrompt' => $this->documentAnalysisPrompt,
                        'parteRepresentada' => $this->parteRepresentada,
                        'papelProcessual' => $this->papelProcessual,
                        'objetivoAnalise' => $this->objetivoAnalise,
                        'prazoEmCurso' => $this->prazoEmCurso,
                        'aiProvider' => $this->aiProvider,
                        'ai_provider' => $this->aiProvider,
                        'analysisStrategy' => $this->analysisStrategy,
                        'deepThinkingEnabled' => $this->deepThinkingEnabled,
                        'deep_thinking_enabled' => $this->deepThinkingEnabled,
                        'aiModelId' => $this->aiModelId,
                        'ai_model_id' => $this->aiModelId,
                        'mapModelId' => $this->mapModelId,
                        'map_model_id' => $this->mapModelId,
                        'chave' => $this->chave,
                        'userLogin' => $this->userLogin,
                        'senha' => $this->senha,
                        'judicialUserId' => $this->judicialUserId,
                        'reduceStrategy' => 'auto',
                        'reduce_strategy' => 'auto',
                    ],
                ]);
            }

            $documentAnalysis->update([
                'parte_representada' => $this->parteRepresentada ?? $documentAnalysis->parte_representada,
                'papel_processual' => $this->papelProcessual ?? $documentAnalysis->papel_processual,
                'objetivo_analise' => $this->objetivoAnalise ?? $documentAnalysis->objetivo_analise,
                'prazo_em_curso' => $this->prazoEmCurso ?? $documentAnalysis->prazo_em_curso,
            ]);

            Log::info('AnalyzeProcessDocumentsJob: Registro de análise criado', [
                'analysis_id' => $documentAnalysis->id,
            ]);

            // === DOWNLOAD EM LOTE ÚNICO ===
            // Baixa todos os documentos em uma única chamada SOAP ao e-Proc
            $this->downloadAllDocuments($documentAnalysis);

            // Inicializa para map-reduce
            $documentAnalysis->initializeMapReduce($totalDocs);

            // Verifica se algum documento foi baixado com sucesso
            $pendingCount = $documentAnalysis->microAnalyses()
                ->where('status', 'pending')
                ->where('reduce_level', 0)
                ->count();

            if ($pendingCount === 0) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Nenhum documento pôde ser baixado com sucesso',
                ]);

                $this->sendNotification($user, 'Análise Falhou', 'Nenhum documento pôde ser baixado com sucesso.', 'danger');
                return;
            }

            Log::info('AnalyzeProcessDocumentsJob: Downloads concluídos, disparando inventário', [
                'analysis_id' => $documentAnalysis->id,
                'docs_pendentes' => $pendingCount,
                'docs_falhos' => $totalDocs - $pendingCount,
            ]);

            $inventoryPayload = [
                'analysis_id' => $documentAnalysis->id,
                'ai_provider' => $this->aiProvider,
                'deep_thinking_enabled' => $this->deepThinkingEnabled,
                'contexto_dados' => $this->contextoDados,
                'ai_model_id' => $this->aiModelId,
                'user_id' => $this->userId,
                'reduce_strategy' => 'auto',
                'map_model_id' => $this->mapModelId,
            ];

            if ((bool) config('analysis.graph_runner.enabled', false)) {
                $runner = new GraphRunner();
                $runner->run(new InventoryNode(), GraphState::fromArray($inventoryPayload));
            } else {
                // Caminho legado permanece como padrão durante o rollout gradual.
                BuildInventoryJob::dispatch(
                    $documentAnalysis->id,
                    $this->aiProvider,
                    $this->deepThinkingEnabled,
                    $this->contextoDados,
                    $this->aiModelId,
                    $this->userId,
                    'auto',
                    $this->mapModelId
                )->onQueue('analysis');
            }

        } catch (\Exception $e) {
            Log::error('AnalyzeProcessDocumentsJob: Erro geral', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($documentAnalysis)) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

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
     * Baixa todos os documentos em uma única chamada SOAP ao e-Proc
     * e cria os registros DocumentMicroAnalysis para cada um.
     */
    private function downloadAllDocuments(DocumentAnalysis $documentAnalysis): void
    {
        $eprocService = new EprocService($this->userLogin, $this->senha);
        $pdfService = new PdfToTextService();

        // Coleta todos os IDs de documentos
        $idsDocumentos = array_map(
            fn($doc) => $doc['idDocumento'],
            $this->documentos
        );

        // Monta mapa de metadados por idDocumento para acesso rápido
        $documentosMetadata = [];
        foreach ($this->documentos as $index => $doc) {
            $documentosMetadata[$doc['idDocumento']] = [
                'index' => $index,
                'descricao' => $doc['descricao'] ?? "Documento " . ($index + 1),
                'mimetype' => strtolower($doc['conteudo']['mimetype'] ?? ''),
            ];
        }

        $this->verboseLog('AnalyzeProcessDocumentsJob: Baixando todos os documentos em lote', [
            'analysis_id' => $documentAnalysis->id,
            'total_ids' => count($idsDocumentos),
        ]);

        // Uma única chamada SOAP para buscar todos os documentos
        try {
            $resultado = $eprocService->consultarDocumentosProcesso(
                $this->numeroProcesso,
                $idsDocumentos,
                true,
                $this->chave
            );
        } catch (\Exception $e) {
            Log::error('AnalyzeProcessDocumentsJob: Falha no download em lote', [
                'analysis_id' => $documentAnalysis->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Extrai lista de documentos da resposta SOAP
        $documentosRetornados = $this->extractDocumentosFromResponse($resultado);

        $this->verboseLog('AnalyzeProcessDocumentsJob: Documentos recebidos do e-Proc', [
            'analysis_id' => $documentAnalysis->id,
            'retornados' => count($documentosRetornados),
            'solicitados' => count($idsDocumentos),
        ]);

        // Processa cada documento retornado
        foreach ($documentosRetornados as $docRetornado) {
            $idDocumento = $docRetornado['idDocumento'] ?? null;

            if (!$idDocumento || !isset($documentosMetadata[$idDocumento])) {
                continue;
            }

            $metadata = $documentosMetadata[$idDocumento];
            unset($documentosMetadata[$idDocumento]); // Marca como processado

            try {
                $this->processDocument(
                    $documentAnalysis,
                    $docRetornado,
                    $metadata,
                    $pdfService
                );
            } catch (\Exception $e) {
                Log::error('AnalyzeProcessDocumentsJob: Erro ao processar documento', [
                    'id_documento' => $idDocumento,
                    'error' => $e->getMessage(),
                ]);

                DocumentMicroAnalysis::create([
                    'document_analysis_id' => $documentAnalysis->id,
                    'document_index' => $metadata['index'],
                    'id_documento' => $idDocumento,
                    'descricao' => $metadata['descricao'],
                    'mimetype' => $metadata['mimetype'],
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'reduce_level' => 0,
                ]);
            }
        }

        // Cria registros de falha para documentos não retornados pelo e-Proc
        foreach ($documentosMetadata as $idDocumento => $metadata) {
            Log::warning('AnalyzeProcessDocumentsJob: Documento não retornado pelo e-Proc', [
                'id_documento' => $idDocumento,
            ]);

            DocumentMicroAnalysis::create([
                'document_analysis_id' => $documentAnalysis->id,
                'document_index' => $metadata['index'],
                'id_documento' => $idDocumento,
                'descricao' => $metadata['descricao'],
                'mimetype' => $metadata['mimetype'],
                'status' => 'failed',
                'error_message' => 'Documento não retornado pelo e-Proc',
                'reduce_level' => 0,
            ]);
        }
    }

    /**
     * Extrai a lista de documentos da resposta SOAP do e-Proc.
     */
    private function extractDocumentosFromResponse(array $resultado): array
    {
        $documentos = null;

        if (isset($resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'])) {
            $documentos = $resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'];
        } elseif (isset($resultado['documento'])) {
            $documentos = $resultado['documento'];
        }

        if (empty($documentos)) {
            return [];
        }

        // Se retornou um único documento (array associativo), envolve em array
        if (isset($documentos['idDocumento'])) {
            $documentos = [$documentos];
        }

        return $documentos;
    }

    /**
     * Processa um documento individual: salva original, extrai texto e cria DocumentMicroAnalysis.
     */
    private function processDocument(
        DocumentAnalysis $documentAnalysis,
        array $docRetornado,
        array $metadata,
        PdfToTextService $pdfService
    ): void {
        $idDocumento = $docRetornado['idDocumento'];
        $mimetype = $metadata['mimetype'];
        $isImage = str_starts_with($mimetype, 'image/');
        $isHtml = $mimetype === 'text/html' || str_contains($mimetype, 'html');

        // Extrai conteúdo base64
        $conteudoBase64 = null;
        if (is_array($docRetornado['conteudo'] ?? null) && isset($docRetornado['conteudo']['conteudo'])) {
            $conteudoBase64 = $docRetornado['conteudo']['conteudo'];
        } elseif (is_string($docRetornado['conteudo'] ?? null)) {
            $conteudoBase64 = $docRetornado['conteudo'];
        }

        if (empty($conteudoBase64)) {
            throw new \RuntimeException('Documento sem conteúdo');
        }

        // Salva original em disco para envio multimodal
        $originalContentPath = $this->saveOriginalContent(
            $documentAnalysis->id,
            $conteudoBase64,
            $mimetype,
            $idDocumento
        );

        $texto = '';
        $processingStrategy = 'text';
        $isScanned = null;

        if ($isImage) {
            $processingStrategy = 'vision';
            $texto = $this->extractTextFromImage($conteudoBase64, $mimetype, $idDocumento);
        } elseif ($isHtml) {
            // HTMLs do e-Proc frequentemente são wrappers de documentos escaneados.
            // Renderizar o HTML completo para PDF captura a visualização real do documento
            // (imagens, CSS, layout) e permite processá-lo pelo pipeline de PDF.
            $htmlToPdfService = new HtmlToPdfService();

            if ($htmlToPdfService->isAvailable()) {
                $pdfBase64 = $htmlToPdfService->convertFromBase64($conteudoBase64, "doc_{$idDocumento}");

                if ($pdfBase64) {
                    // Salva o PDF renderizado como conteúdo original (substituindo o HTML)
                    $pdfPath = $this->saveOriginalContent(
                        $documentAnalysis->id,
                        $pdfBase64,
                        'application/pdf',
                        $idDocumento . '_rendered'
                    );

                    if ($pdfPath) {
                        $originalContentPath = $pdfPath;
                        $mimetype = 'application/pdf';
                    }

                    // Processa como PDF (extrai texto ou detecta escaneamento)
                    $pdfResult = $pdfService->extractTextWithMetadata(
                        $pdfBase64,
                        "doc_{$idDocumento}_rendered.pdf"
                    );

                    $texto = $pdfResult['text'];
                    $isScanned = $pdfResult['is_scanned'];
                    $processingStrategy = $isScanned ? 'pdf_ocr' : 'pdf_text';

                    $this->verboseLog('AnalyzeProcessDocumentsJob: HTML convertido para PDF via wkhtmltopdf', [
                        'id_documento' => $idDocumento,
                        'chars_extracted' => mb_strlen($texto),
                        'is_scanned' => $isScanned,
                        'strategy' => $processingStrategy,
                    ]);
                } else {
                    // wkhtmltopdf falhou na conversão — fallback para extração de texto
                    Log::warning('AnalyzeProcessDocumentsJob: wkhtmltopdf falhou, usando extração de texto', [
                        'id_documento' => $idDocumento,
                    ]);
                    $processingStrategy = 'text';
                    $texto = $this->extractTextFromHtml($conteudoBase64, $idDocumento);
                }
            } else {
                // wkhtmltopdf não disponível — fallback para extração de texto
                Log::warning('AnalyzeProcessDocumentsJob: wkhtmltopdf não disponível, usando extração de texto', [
                    'id_documento' => $idDocumento,
                ]);
                $processingStrategy = 'text';
                $texto = $this->extractTextFromHtml($conteudoBase64, $idDocumento);
            }
        } else {
            // PDF e outros
            $pdfResult = $pdfService->extractTextWithMetadata(
                $conteudoBase64,
                "doc_{$idDocumento}.pdf"
            );

            $texto = $pdfResult['text'];
            $isScanned = $pdfResult['is_scanned'];
            $processingStrategy = $isScanned ? 'pdf_ocr' : 'pdf_text';

            $this->verboseLog('AnalyzeProcessDocumentsJob: PDF processado', [
                'id_documento' => $idDocumento,
                'chars_extracted' => mb_strlen($texto),
                'is_scanned' => $isScanned,
                'strategy' => $processingStrategy,
                'page_count' => $pdfResult['page_count'] ?? null,
            ]);
        }

        // Cria registro de micro-análise
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $documentAnalysis->id,
            'document_index' => $metadata['index'],
            'id_documento' => $idDocumento,
            'descricao' => $metadata['descricao'],
            'mimetype' => $mimetype,
            'original_content_path' => $originalContentPath,
            'processing_strategy' => $processingStrategy,
            'is_scanned' => $isScanned,
            'extracted_text' => $texto,
            'status' => 'pending',
            'reduce_level' => 0,
        ]);

        $this->verboseLog('AnalyzeProcessDocumentsJob: Documento processado', [
            'analysis_id' => $documentAnalysis->id,
            'document_index' => $metadata['index'],
            'id_documento' => $idDocumento,
            'chars' => mb_strlen($texto),
            'strategy' => $processingStrategy,
            'has_original' => $originalContentPath !== null,
        ]);
    }

    /**
     * Salva o conteúdo original do documento em disco.
     */
    private function saveOriginalContent(int $analysisId, string $base64Content, string $mimetype, string $idDocumento): ?string
    {
        try {
            $extensionMap = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
                'image/gif' => 'gif', 'image/bmp' => 'bmp', 'image/tiff' => 'tiff',
                'image/webp' => 'webp', 'application/pdf' => 'pdf', 'text/html' => 'html',
            ];
            $extension = $extensionMap[strtolower($mimetype)] ?? 'bin';
            $path = "document-originals/{$analysisId}/{$idDocumento}.{$extension}";

            $decodedContent = base64_decode($base64Content);
            if ($decodedContent === false) {
                return null;
            }

            Storage::disk('local')->put($path, $decodedContent);

            $this->verboseLog('AnalyzeProcessDocumentsJob: Conteúdo original salvo', [
                'id_documento' => $idDocumento,
                'path' => $path,
                'size_bytes' => strlen($decodedContent),
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::warning('AnalyzeProcessDocumentsJob: Falha ao salvar conteúdo original', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrai texto de uma imagem via OCR.
     */
    private function extractTextFromImage(string $base64Content, string $mimetype, string $idDocumento): string
    {
        $ocrService = new OcrService();

        if (!$ocrService->isAvailable()) {
            return '';
        }

        try {
            $texto = $ocrService->extractText($base64Content, $mimetype, "doc_{$idDocumento}");

            $this->verboseLog('AnalyzeProcessDocumentsJob: Texto extraído da imagem via OCR', [
                'id_documento' => $idDocumento,
                'chars_extracted' => mb_strlen($texto),
            ]);

            return $texto;
        } catch (\Exception $e) {
            Log::warning('AnalyzeProcessDocumentsJob: OCR falhou para imagem', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Extrai texto de conteúdo HTML.
     */
    private function extractTextFromHtml(string $base64Content, string $idDocumento): string
    {
        $htmlService = new HtmlToTextService();
        $texto = $htmlService->extractText($base64Content, "doc_{$idDocumento}");

        // Verifica imagens embutidas no HTML
        $embeddedImages = $htmlService->extractEmbeddedImages($base64Content);

        if (!empty($embeddedImages)) {
            $ocrService = new OcrService();

            if ($ocrService->isAvailable()) {
                foreach ($embeddedImages as $index => $image) {
                    try {
                        $imageText = $ocrService->extractText(
                            $image['content'],
                            $image['mimetype'],
                            "doc_{$idDocumento}_img_{$index}"
                        );

                        if (!empty($imageText)) {
                            $texto .= "\n\n--- Imagem " . ($index + 1) . " ---\n" . $imageText;
                        }
                    } catch (\Exception $e) {
                        Log::warning('AnalyzeProcessDocumentsJob: OCR falhou em imagem do HTML', [
                            'id_documento' => $idDocumento,
                            'image_index' => $index,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return trim($texto);
    }

    /**
     * Envia notificação para o usuário
     */
    private function sendNotification(?User $user, string $title, string $body, string $status = 'info'): void
    {
        if (!$user) {
            return;
        }

        try {
            NotificationService::send($user, $title, $body, $status);
        } catch (\Exception $e) {
            Log::warning('AnalyzeProcessDocumentsJob: Erro ao enviar notificação', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Formata array de assuntos para string
     */
    private function formatAssuntosString(array $assuntos): ?string
    {
        if (empty($assuntos)) {
            return null;
        }

        $nomes = array_map(function ($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? $assunto['codigoNacional']
                ?? null;
        }, $assuntos);

        $nomes = array_filter($nomes);

        return !empty($nomes) ? implode(', ', $nomes) : null;
    }

    private function verboseLog(string $message, array $context = []): void
    {
        if (!config('analysis.telemetry.verbose_job_logs', false)) {
            return;
        }

        Log::info($message, $context);
    }
}
