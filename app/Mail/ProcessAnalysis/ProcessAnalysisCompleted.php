<?php

namespace App\Mail\ProcessAnalysis;

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

    public function __construct(
        public DocumentAnalysis $analysis,
        public User $user,
    ) {
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
        try {
            $pdf = PdfService::generateDocumentAnalysisPdf($this->analysis);
            $pdfContent = $pdf->output();
            $pdfFileName = 'Analise_Processo_' . preg_replace('/[^0-9]/', '', $this->analysis->numero_processo) . '.pdf';

            Log::info('ProcessAnalysisCompleted: PDF gerado com sucesso', [
                'analysis_id' => $this->analysis->id,
                'pdf_size' => strlen($pdfContent),
            ]);

            return [
                Attachment::fromData(
                    fn () => $pdfContent,
                    $pdfFileName
                )->withMime('application/pdf'),
            ];
        } catch (\Exception $e) {
            Log::error('ProcessAnalysisCompleted: Erro ao gerar PDF, enviando e-mail sem anexo', [
                'analysis_id' => $this->analysis->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
