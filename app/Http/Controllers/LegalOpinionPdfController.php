<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Services\PdfService;
use Illuminate\Http\Response;
use App\Traits\WithOtelTracing;

class LegalOpinionPdfController extends Controller
{
    use WithOtelTracing;

    /**
     * Download do parecer jurídico em PDF
     */
    public function download(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.legal_opinion_pdf.download', [
            'contract.analysis.id' => $id,
        ]);

        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy com verificação de ownership/role
        $this->authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        // Nome do arquivo
        $fileName = 'parecer-juridico-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        $this->finishSpanSuccess($span);
        $this->detachScope($scope);

        return $pdf->download($fileName);
    }

    /**
     * Visualizar o parecer jurídico em PDF (stream)
     */
    public function view(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.legal_opinion_pdf.view', [
            'contract.analysis.id' => $id,
        ]);

        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy com verificação de ownership/role
        $this->authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        $this->finishSpanSuccess($span);
        $this->detachScope($scope);

        return $pdf->stream('parecer-juridico-' . $analysis->id . '.pdf');
    }
}
