<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LegalOpinionPdfController extends Controller
{
    /**
     * Download do parecer jurídico em PDF
     */
    public function download(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar este parecer.');
        }

        // Verifica se o parecer está concluído
        if (!$analysis->isLegalOpinionCompleted()) {
            abort(404, 'Parecer jurídico não encontrado ou ainda não foi gerado.');
        }

        // Prepara os dados para o PDF
        $data = [
            'analysis' => $analysis,
            'content' => $analysis->legal_opinion_result,
            'generatedAt' => $analysis->updated_at->format('d/m/Y H:i'),
            'interestedParty' => $analysis->interested_party_name,
            'fileName' => $analysis->file_name,
        ];

        // Gera o PDF
        $pdf = Pdf::loadView('pdf.legal-opinion', $data);

        // Configura o PDF
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isPhpEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Serif');
        $pdf->setOption('isFontSubsettingEnabled', true);

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

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar este parecer.');
        }

        // Verifica se o parecer está concluído
        if (!$analysis->isLegalOpinionCompleted()) {
            abort(404, 'Parecer jurídico não encontrado ou ainda não foi gerado.');
        }

        // Prepara os dados para o PDF
        $data = [
            'analysis' => $analysis,
            'content' => $analysis->legal_opinion_result,
            'generatedAt' => $analysis->updated_at->format('d/m/Y H:i'),
            'interestedParty' => $analysis->interested_party_name,
            'fileName' => $analysis->file_name,
        ];

        // Gera o PDF
        $pdf = Pdf::loadView('pdf.legal-opinion', $data);

        // Configura o PDF
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isPhpEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Serif');
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->stream('parecer-juridico-' . $analysis->id . '.pdf');
    }
}
