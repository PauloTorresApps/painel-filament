<?php

namespace App\Mail;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAnalysisCompleted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Conteúdo do PDF pré-gerado (para evitar problemas com serialização)
     */
    protected ?string $pdfContent = null;

    protected string $pdfFileName;

    public function __construct(
        public DocumentAnalysis $analysis,
        public User $user,
    ) {
        $this->pdfFileName = 'Analise_Processo_' . preg_replace('/[^0-9]/', '', $this->analysis->numero_processo) . '.pdf';

        // Gerar PDF no momento da construção (antes de enfileirar)
        $this->generatePdf();
    }

    /**
     * Gera o PDF e armazena o conteúdo
     */
    protected function generatePdf(): void
    {
        try {
            $pdf = PdfService::generateDocumentAnalysisPdf($this->analysis);
            $this->pdfContent = $pdf->output();

            Log::info('ProcessAnalysisCompleted: PDF gerado com sucesso', [
                'analysis_id' => $this->analysis->id,
                'pdf_size' => strlen($this->pdfContent),
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessAnalysisCompleted: Erro ao gerar PDF', [
                'analysis_id' => $this->analysis->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->pdfContent = null;
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Análise Processual Concluída - Processo {$this->analysis->numero_processo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.process-analysis-completed',
            with: [
                'userName' => $this->user->name,
                'numeroProcesso' => $this->analysis->numero_processo,
                'classeProcessual' => $this->analysis->classe_processual,
                'assuntos' => $this->analysis->assuntos,
                'totalDocumentos' => $this->analysis->total_documents,
                'completedAt' => $this->analysis->updated_at->format('d/m/Y \à\s H:i'),
            ],
        );
    }

    public function attachments(): array
    {
        // Se o PDF não foi gerado na construção, tenta novamente
        if ($this->pdfContent === null) {
            $this->generatePdf();
        }

        // Se ainda não tem PDF, retorna array vazio
        if ($this->pdfContent === null) {
            Log::warning('ProcessAnalysisCompleted: Enviando e-mail sem anexo PDF', [
                'analysis_id' => $this->analysis->id,
            ]);

            return [];
        }

        return [
            Attachment::fromData(
                fn () => $this->pdfContent,
                $this->pdfFileName
            )->withMime('application/pdf'),
        ];
    }
}
