<?php

namespace App\Mail\ContractAnalysis;

use App\Models\ContractAnalysis;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContractAnalysisCompleted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Conteúdo do PDF pré-gerado (para evitar problemas com serialização)
     */
    protected ?string $pdfContent = null;

    protected string $pdfFileName;

    public function __construct(
        public ContractAnalysis $analysis,
        public User $user,
    ) {
        $this->pdfFileName = 'Analise_Contrato_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($this->analysis->file_name, PATHINFO_FILENAME)) . '.pdf';

        // Gerar PDF no momento da construção (antes de enfileirar)
        $this->generatePdf();
    }

    /**
     * Gera o PDF e armazena o conteúdo
     */
    protected function generatePdf(): void
    {
        try {
            $pdf = PdfService::generateContractAnalysisPdf($this->analysis);
            $this->pdfContent = $pdf->output();

            Log::info('ContractAnalysisCompleted: PDF gerado com sucesso', [
                'analysis_id' => $this->analysis->id,
                'pdf_size' => strlen($this->pdfContent),
            ]);
        } catch (\Exception $e) {
            Log::error('ContractAnalysisCompleted: Erro ao gerar PDF', [
                'analysis_id' => $this->analysis->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->pdfContent = null;
        }
    }

    public function envelope(): Envelope
    {
        $subject = 'Análise Contratual Concluída';
        if ($this->analysis->file_name) {
            $subject .= " - {$this->analysis->file_name}";
        }

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $processingTime = $this->analysis->processing_time_ms
            ? number_format($this->analysis->processing_time_ms / 1000, 1) . ' segundos'
            : null;

        return new Content(
            view: 'emails.contract-analysis-completed',
            with: [
                'userName' => $this->user->name,
                'fileName' => $this->analysis->file_name,
                'interestedParty' => $this->analysis->interested_party_name,
                'processingTime' => $processingTime,
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
            Log::warning('ContractAnalysisCompleted: Enviando e-mail sem anexo PDF', [
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
