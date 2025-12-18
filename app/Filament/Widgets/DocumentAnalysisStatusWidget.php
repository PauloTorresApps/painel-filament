<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAnalysis;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class DocumentAnalysisStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.document-analysis-status-widget';

    protected int | string | array $columnSpan = 'full';

    // Desabilita auto-refresh padrão do Livewire
    protected static bool $isLazy = false;

    public ?string $numeroProcesso = null;

    public int $page = 1;

    public int $perPage = 10;

    public function mount(?string $numeroProcesso = null): void
    {
        $this->numeroProcesso = $numeroProcesso;
    }

    public function getAnalyses()
    {
        $query = DocumentAnalysis::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        if ($this->numeroProcesso) {
            $query->where('numero_processo', $this->numeroProcesso);
        }

        return $query->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function getTotalPages(): int
    {
        $total = DocumentAnalysis::where('user_id', Auth::id())->count();
        return (int) ceil($total / $this->perPage);
    }

    public function getProcessingCount(): int
    {
        return DocumentAnalysis::where('user_id', Auth::id())
            ->where('status', 'processing')
            ->count();
    }

    public function getPendingCount(): int
    {
        return DocumentAnalysis::where('user_id', Auth::id())
            ->where('status', 'pending')
            ->count();
    }

    public function getCompletedCount(): int
    {
        return DocumentAnalysis::where('user_id', Auth::id())
            ->where('status', 'completed')
            ->count();
    }

    public function getFailedCount(): int
    {
        return DocumentAnalysis::where('user_id', Auth::id())
            ->where('status', 'failed')
            ->count();
    }

    // Polling a cada 10 segundos se houver análises em andamento
    // Aumentado de 5s para 10s para reduzir carga
    public function getPollingInterval(): ?string
    {
        $hasActiveAnalyses = DocumentAnalysis::where('user_id', Auth::id())
            ->whereIn('status', ['processing', 'pending'])
            ->exists();

        return $hasActiveAnalyses ? '10s' : null;
    }
}
