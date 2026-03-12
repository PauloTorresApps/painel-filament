<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Services\PdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use App\Traits\WithOtelTracing;
use OpenTelemetry\API\Trace\StatusCode;

class ContractAnalysisPdfController extends Controller
{
    use WithOtelTracing;

    public function download(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.contract_analysis_pdf.download', [
            'contract.analysis.id' => $id,
        ]);

        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        Gate::authorize('downloadPdf', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateContractAnalysisPdf($analysis);

        // Nome do arquivo
        $fileName = 'analise-contratual-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.pdf';

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

        return $pdf->download($fileName);
    }

    /**
     * Visualizar a análise contratual em PDF (stream)
     */
    public function view(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.contract_analysis_pdf.view', [
            'contract.analysis.id' => $id,
        ]);

        $analysis = ContractAnalysis::findOrFail($id);

        // Autoriza usando Policy
        Gate::authorize('downloadPdf', $analysis);

        // Gera PDF usando o serviço
        $pdf = PdfService::generateContractAnalysisPdf($analysis);

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

        return $pdf->stream('analise-contratual-' . $analysis->id . '.pdf');
    }
}
