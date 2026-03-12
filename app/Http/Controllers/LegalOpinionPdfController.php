<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Services\PdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use App\Traits\WithOtelTracing;
use OpenTelemetry\API\Trace\StatusCode;

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

        // Autoriza usando Policy
        Gate::authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        // Nome do arquivo
        $fileName = 'parecer-juridico-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

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

        // Autoriza usando Policy
        Gate::authorize('downloadLegalOpinion', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateLegalOpinionPdf($analysis);

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

        return $pdf->stream('parecer-juridico-' . $analysis->id . '.pdf');
    }
}
