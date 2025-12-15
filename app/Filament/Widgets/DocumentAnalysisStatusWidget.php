<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAnalysis;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class DocumentAnalysisStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.document-analysis-status-widget';

    protected int | string | array $columnSpan = 'full';

    public ?string $numeroProcesso = null;

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

        return $query->limit(10)->get();
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

    // Polling a cada 5 segundos se houver análises em andamento
    public function getPollingInterval(): ?string
    {
        if ($this->getProcessingCount() > 0 || $this->getPendingCount() > 0) {
            return '5s';
        }

        return null;
    }
}
