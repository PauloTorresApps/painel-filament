<?php

namespace App\Mail;

use App\Models\ContractAnalysis;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractAnalysisCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContractAnalysis $analysis,
        public User $user,
    ) {}

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
        $pdf = PdfService::generateContractAnalysisPdf($this->analysis);
        $fileName = 'Analise_Contrato_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($this->analysis->file_name, PATHINFO_FILENAME)) . '.pdf';

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdf->output(),
                $fileName
            )->withMime('application/pdf'),
        ];
    }
}
