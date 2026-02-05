<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Services\PdfService;
use Illuminate\Http\Response;

class LegalOpinionPdfController extends Controller
{
    /**
     * Download do parecer jurídico em PDF
     */
    public function download(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        $this->authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        // Nome do arquivo
        $fileName = 'parecer-juridico-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        return $pdf->download($fileName);
    }

    /**
     * Visualizar o parecer jurídico em PDF (stream)
     */
    public function view(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        $this->authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        return $pdf->stream('parecer-juridico-' . $analysis->id . '.pdf');
    }
}
