<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Services\EprocService;
use App\Services\HtmlToTextService;
use App\Services\OcrService;
use App\Services\PdfToTextService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para download e extração de texto de um documento individual.
 * Utiliza o trait Batchable para processamento paralelo via Bus::batch().
 *
 * Além de extrair texto (fallback), salva o conteúdo original em disco
 * para envio direto à OpenRouter via multimodal (visão/PDF nativo).
 */
class DownloadDocumentJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $timeout = 300; // 5 minutos por documento
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $documentAnalysisId,
        public int $documentIndex,
        public array $documento,
        public string $numeroProcesso,
        public string $userLogin,
        public string $senha
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Verifica se o batch foi cancelado
        if ($this->batch()?->cancelled()) {
            Log::info('DownloadDocumentJob: Batch cancelado, pulando documento', [
                'document_index' => $this->documentIndex,
                'id_documento' => $this->documento['idDocumento'] ?? null,
            ]);
            return;
        }

        try {
            $documentAnalysis = DocumentAnalysis::find($this->documentAnalysisId);

            if (!$documentAnalysis || $documentAnalysis->status === 'cancelled') {
                Log::info('DownloadDocumentJob: Análise não encontrada ou cancelada', [
                    'analysis_id' => $this->documentAnalysisId
                ]);
                return;
            }

            $pdfService = new PdfToTextService();
            $eprocService = new EprocService($this->userLogin, $this->senha);

            // Obtém o mimetype do documento
            $mimetype = strtolower($this->documento['conteudo']['mimetype'] ?? '');
            $isImage = str_starts_with($mimetype, 'image/');
            $isHtml = $mimetype === 'text/html' || str_contains($mimetype, 'html');

            Log::info('DownloadDocumentJob: Baixando documento', [
                'analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'id_documento' => $this->documento['idDocumento'],
                'mimetype' => $mimetype,
            ]);

            // Busca o conteúdo do documento
            $documentoCompleto = $this->fetchDocumento($eprocService, $this->documento['idDocumento']);

            if (!$documentoCompleto || empty($documentoCompleto['conteudo'])) {
                Log::warning('DownloadDocumentJob: Documento sem conteúdo', [
                    'id_documento' => $this->documento['idDocumento']
                ]);

                // Cria micro-análise com status de falha
                DocumentMicroAnalysis::create([
                    'document_analysis_id' => $this->documentAnalysisId,
                    'document_index' => $this->documentIndex,
                    'id_documento' => $this->documento['idDocumento'],
                    'descricao' => $this->documento['descricao'] ?? "Documento " . ($this->documentIndex + 1),
                    'mimetype' => $mimetype,
                    'status' => 'failed',
                    'error_message' => 'Documento sem conteúdo',
                    'reduce_level' => 0,
                ]);
                return;
            }

            $texto = '';
            $originalContentPath = null;
            $processingStrategy = 'text';
            $isScanned = null;

            // Salva o conteúdo original em disco para envio multimodal à OpenRouter
            $originalContentPath = $this->saveOriginalContent(
                $documentoCompleto['conteudo'],
                $mimetype,
                $this->documento['idDocumento']
            );

            if ($isImage) {
                $processingStrategy = 'vision';

                // Para imagens, extrai texto via OCR como fallback
                $ocrService = new OcrService();

                if ($ocrService->isAvailable()) {
                    try {
                        $texto = $ocrService->extractText(
                            $documentoCompleto['conteudo'],
                            $mimetype,
                            "doc_{$this->documento['idDocumento']}"
                        );

                        Log::info('DownloadDocumentJob: Texto extraído da imagem via OCR (fallback)', [
                            'id_documento' => $this->documento['idDocumento'],
                            'chars_extracted' => mb_strlen($texto),
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('DownloadDocumentJob: OCR falhou para imagem, análise dependerá de visão', [
                            'id_documento' => $this->documento['idDocumento'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::info('DownloadDocumentJob: Tesseract indisponível, imagem será processada via visão', [
                        'id_documento' => $this->documento['idDocumento'],
                    ]);
                }
            } elseif ($isHtml) {
                $processingStrategy = 'text';

                // Para HTML, extrai texto removendo tags
                $htmlService = new HtmlToTextService();

                $texto = $htmlService->extractText(
                    $documentoCompleto['conteudo'],
                    "doc_{$this->documento['idDocumento']}"
                );

                Log::info('DownloadDocumentJob: Texto extraído do HTML', [
                    'id_documento' => $this->documento['idDocumento'],
                    'chars_extracted' => mb_strlen($texto),
                ]);

                // Verifica se o HTML contém imagens embutidas (ex: documentos escaneados)
                $embeddedImages = $htmlService->extractEmbeddedImages($documentoCompleto['conteudo']);

                if (!empty($embeddedImages)) {
                    $ocrService = new OcrService();

                    if ($ocrService->isAvailable()) {
                        $textoOcr = '';

                        foreach ($embeddedImages as $index => $image) {
                            try {
                                $imageText = $ocrService->extractText(
                                    $image['content'],
                                    $image['mimetype'],
                                    "doc_{$this->documento['idDocumento']}_img_{$index}"
                                );

                                if (!empty($imageText)) {
                                    $textoOcr .= "\n\n--- Imagem " . ($index + 1) . " ---\n" . $imageText;
                                }
                            } catch (\Exception $e) {
                                Log::warning('DownloadDocumentJob: Falha OCR em imagem embutida do HTML', [
                                    'id_documento' => $this->documento['idDocumento'],
                                    'image_index' => $index,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        if (!empty($textoOcr)) {
                            $texto = trim($texto . "\n" . $textoOcr);

                            Log::info('DownloadDocumentJob: OCR aplicado em imagens do HTML', [
                                'id_documento' => $this->documento['idDocumento'],
                                'images_count' => count($embeddedImages),
                                'total_chars' => mb_strlen($texto),
                            ]);
                        }
                    } else {
                        Log::warning('DownloadDocumentJob: Tesseract não disponível para OCR de imagens do HTML', [
                            'id_documento' => $this->documento['idDocumento'],
                            'images_count' => count($embeddedImages),
                        ]);
                    }
                }
            } else {
                // Para PDFs e outros documentos, extrai texto com metadados de detecção
                $pdfResult = $pdfService->extractTextWithMetadata(
                    $documentoCompleto['conteudo'],
                    "doc_{$this->documento['idDocumento']}.pdf"
                );

                $texto = $pdfResult['text'];
                $isScanned = $pdfResult['is_scanned'];
                $processingStrategy = $isScanned ? 'pdf_ocr' : 'pdf_text';

                Log::info('DownloadDocumentJob: PDF processado', [
                    'id_documento' => $this->documento['idDocumento'],
                    'chars_extracted' => mb_strlen($texto),
                    'is_scanned' => $isScanned,
                    'strategy' => $processingStrategy,
                    'page_count' => $pdfResult['page_count'] ?? null,
                ]);
            }

            // Cria registro de micro-análise com metadados multimodal
            DocumentMicroAnalysis::create([
                'document_analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'id_documento' => $this->documento['idDocumento'],
                'descricao' => $this->documento['descricao'] ?? "Documento " . ($this->documentIndex + 1),
                'mimetype' => $mimetype,
                'original_content_path' => $originalContentPath,
                'processing_strategy' => $processingStrategy,
                'is_scanned' => $isScanned,
                'extracted_text' => $texto,
                'status' => 'pending',
                'reduce_level' => 0,
            ]);

            Log::info('DownloadDocumentJob: Documento processado com sucesso', [
                'analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'chars' => mb_strlen($texto),
                'strategy' => $processingStrategy,
                'has_original' => $originalContentPath !== null,
            ]);

        } catch (\Exception $e) {
            Log::error('DownloadDocumentJob: Erro ao processar documento', [
                'id_documento' => $this->documento['idDocumento'] ?? null,
                'error' => $e->getMessage()
            ]);

            // Cria micro-análise com status de falha
            DocumentMicroAnalysis::create([
                'document_analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'id_documento' => $this->documento['idDocumento'] ?? null,
                'descricao' => $this->documento['descricao'] ?? "Documento " . ($this->documentIndex + 1),
                'mimetype' => $this->documento['conteudo']['mimetype'] ?? null,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'reduce_level' => 0,
            ]);

            throw $e;
        }
    }

    /**
     * Salva o conteúdo original do documento em disco para envio multimodal.
     * Retorna o path relativo ao disco 'local', ou null se falhar.
     */
    private function saveOriginalContent(string $base64Content, string $mimetype, string $idDocumento): ?string
    {
        try {
            $extension = $this->getExtensionFromMimetype($mimetype);
            $path = "document-originals/{$this->documentAnalysisId}/{$idDocumento}.{$extension}";

            $decodedContent = base64_decode($base64Content);

            if ($decodedContent === false) {
                Log::warning('DownloadDocumentJob: Falha ao decodificar base64 para salvar original', [
                    'id_documento' => $idDocumento,
                ]);
                return null;
            }

            Storage::disk('local')->put($path, $decodedContent);

            Log::info('DownloadDocumentJob: Conteúdo original salvo em disco', [
                'id_documento' => $idDocumento,
                'path' => $path,
                'size_bytes' => strlen($decodedContent),
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::warning('DownloadDocumentJob: Falha ao salvar conteúdo original', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Determina a extensão do arquivo baseada no mimetype
     */
    private function getExtensionFromMimetype(string $mimetype): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/html' => 'html',
        ];

        return $map[strtolower($mimetype)] ?? 'bin';
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
            Log::error('DownloadDocumentJob: Erro ao buscar documento', [
                'id_documento' => $idDocumento,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
