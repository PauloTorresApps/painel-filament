<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Services\PdfService;
use Illuminate\Http\Response;

class ContractAnalysisPdfController extends Controller
{
    public function download(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        $this->authorize('downloadPdf', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateContractAnalysisPdf($analysis);

        // Nome do arquivo
        $fileName = 'analise-contratual-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        return $pdf->download($fileName);
    }

    /**
     * Visualizar a análise contratual em PDF (stream)
     */
    public function view(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        $this->authorize('downloadPdf', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateContractAnalysisPdf($analysis);

        return $pdf->stream('analise-contratual-' . $analysis->id . '.pdf');
    }
}
