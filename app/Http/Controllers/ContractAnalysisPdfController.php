<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ContractAnalysisPdfController extends Controller
{
    /**
     * Download da análise contratual em PDF
     */
    public function download(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar esta análise.');
        }

        // Verifica se a análise está concluída
        if (!$analysis->isCompleted()) {
            abort(404, 'Análise não encontrada ou ainda não foi concluída.');
        }

        // Prepara os dados para o PDF
        $data = $this->prepareData($analysis);

        // Gera o PDF
        $pdf = $this->generatePdf($data);

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

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar esta análise.');
        }

        // Verifica se a análise está concluída
        if (!$analysis->isCompleted()) {
            abort(404, 'Análise não encontrada ou ainda não foi concluída.');
        }

        // Prepara os dados para o PDF
        $data = $this->prepareData($analysis);

        // Gera o PDF
        $pdf = $this->generatePdf($data);

        return $pdf->stream('analise-contratual-' . $analysis->id . '.pdf');
    }

    /**
     * Prepara os dados para o template PDF
     */
    private function prepareData(ContractAnalysis $analysis): array
    {
        $aiProvider = match($analysis->ai_provider) {
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            'deepseek' => 'DeepSeek',
            default => $analysis->ai_provider ?? null
        };

        $processingTime = $analysis->processing_time_ms
            ? number_format($analysis->processing_time_ms / 1000, 1) . ' segundos'
            : null;

        return [
            'analysis' => $analysis,
            'content' => $analysis->analysis_result,
            'generatedAt' => $analysis->updated_at->format('d/m/Y H:i'),
            'interestedParty' => $analysis->interested_party_name,
            'fileName' => $analysis->file_name,
            'aiProvider' => $aiProvider,
            'processingTime' => $processingTime,
        ];
    }

    /**
     * Gera o PDF com as configurações padrão
     */
    private function generatePdf(array $data)
    {
        $pdf = Pdf::loadView('pdf.contract-analysis', $data);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isPhpEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');
        $pdf->setOption('isFontSubsettingEnabled', true);
        $pdf->setOption('margin_top', 25);
        $pdf->setOption('margin_bottom', 20);
        $pdf->setOption('margin_left', 25);
        $pdf->setOption('margin_right', 20);

        return $pdf;
    }
}
