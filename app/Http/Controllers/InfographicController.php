<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class InfographicController extends Controller
{
    /**
     * Visualizar o infográfico HTML
     */
    public function view(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar este infográfico.');
        }

        // Verifica se o infográfico está concluído
        if (!$analysis->isInfographicCompleted()) {
            abort(404, 'Infográfico não encontrado ou ainda não foi gerado.');
        }

        // Retorna o HTML diretamente
        return response($analysis->infographic_html_result, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Download do infográfico HTML
     */
    public function download(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar este infográfico.');
        }

        // Verifica se o infográfico está concluído
        if (!$analysis->isInfographicCompleted()) {
            abort(404, 'Infográfico não encontrado ou ainda não foi gerado.');
        }

        $fileName = 'infografico-' . $analysis->id . '-' . now()->format('Y-m-d-His') . '.html';

        return response($analysis->infographic_html_result, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Retorna o storyboard JSON (para debug ou reprocessamento)
     */
    public function storyboard(int $id): Response
    {
        $analysis = ContractAnalysis::findOrFail($id);

        // Verifica permissão de acesso
        $user = Auth::user();
        if (!$user->hasRole(['Admin', 'Manager']) && $analysis->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para acessar este storyboard.');
        }

        // Verifica se o infográfico está concluído
        if (!$analysis->isInfographicCompleted() || empty($analysis->infographic_storyboard_json)) {
            abort(404, 'Storyboard não encontrado ou ainda não foi gerado.');
        }

        return response($analysis->infographic_storyboard_json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
