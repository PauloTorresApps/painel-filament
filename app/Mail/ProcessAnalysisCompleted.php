<?php

namespace App\Mail;

use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProcessAnalysisCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentAnalysis $analysis,
        public User $user,
    ) {}

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
        $pdf = PdfService::generateDocumentAnalysisPdf($this->analysis);
        $fileName = 'Analise_Processo_' . preg_replace('/[^0-9]/', '', $this->analysis->numero_processo) . '.pdf';

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdf->output(),
                $fileName
            )->withMime('application/pdf'),
        ];
    }
}
