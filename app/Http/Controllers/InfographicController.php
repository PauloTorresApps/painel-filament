<?php

namespace App\Http\Controllers;

use App\Models\ContractAnalysis;
use App\Traits\WithOtelTracing;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use OpenTelemetry\API\Trace\StatusCode;

class InfographicController extends Controller
{
    use WithOtelTracing;

    /**
     * Visualizar o infográfico HTML
     */
    public function view(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.infographic.view', [
            'contract.analysis.id' => $id,
            'app.user_id' => Auth::id(),
        ]);

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
        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

        return response($analysis->infographic_html_result, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Download do infográfico HTML
     */
    public function download(int $id): Response
    {
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.infographic.download', [
            'contract.analysis.id' => $id,
            'app.user_id' => Auth::id(),
        ]);

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

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

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
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.infographic.storyboard', [
            'contract.analysis.id' => $id,
            'app.user_id' => Auth::id(),
        ]);

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

        $span->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span->end();

        return response($analysis->infographic_storyboard_json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
