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

/**
 * Job para download e extração de texto de um documento individual.
 * Utiliza o trait Batchable para processamento paralelo via Bus::batch().
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

            if ($isImage) {
                // Para imagens, extrai texto usando OCR (Tesseract)
                $ocrService = new OcrService();

                if (!$ocrService->isAvailable()) {
                    throw new \Exception('Tesseract OCR não está disponível para extrair texto da imagem');
                }

                $texto = $ocrService->extractText(
                    $documentoCompleto['conteudo'],
                    $mimetype,
                    "doc_{$this->documento['idDocumento']}"
                );

                Log::info('DownloadDocumentJob: Texto extraído da imagem via OCR', [
                    'id_documento' => $this->documento['idDocumento'],
                    'chars_extracted' => mb_strlen($texto),
                ]);
            } elseif ($isHtml) {
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
            } else {
                // Para PDFs e outros documentos, extrai texto
                $texto = $pdfService->extractText(
                    $documentoCompleto['conteudo'],
                    "doc_{$this->documento['idDocumento']}.pdf"
                );
            }

            // Cria registro de micro-análise
            DocumentMicroAnalysis::create([
                'document_analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'id_documento' => $this->documento['idDocumento'],
                'descricao' => $this->documento['descricao'] ?? "Documento " . ($this->documentIndex + 1),
                'mimetype' => $mimetype,
                'extracted_text' => $texto,
                'status' => 'pending',
                'reduce_level' => 0,
            ]);

            Log::info('DownloadDocumentJob: Documento processado com sucesso', [
                'analysis_id' => $this->documentAnalysisId,
                'document_index' => $this->documentIndex,
                'chars' => mb_strlen($texto),
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
