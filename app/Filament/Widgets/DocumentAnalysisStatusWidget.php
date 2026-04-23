<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAnalysis;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;

class DocumentAnalysisStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.document-analysis-status-widget';

    protected int | string | array $columnSpan = 'full';

    // Desabilita auto-refresh padrão do Livewire
    protected static bool $isLazy = false;

    public ?string $numeroProcesso = null;

    public int $page = 1;

    public int $perPage = 10;

    private const CACHE_TTL_SECONDS = 20;

    public function mount(?string $numeroProcesso = null): void
    {
        $this->numeroProcesso = $numeroProcesso;
    }

    public function getAnalyses()
    {
        return $this->baseQuery()
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
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
        $cacheKey = sprintf(
            'widgets:document-status-total:%s:%s',
            Auth::id() ?? 'guest',
            $this->numeroProcesso ?: 'all'
        );

        $total = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function (): int {
            return (clone $this->baseQuery())->count();
        });

        return (int) ceil($total / $this->perPage);
    }

    public function getProcessingCount(): int
    {
        return $this->getStatusCounts()['processing'];
    }

    public function getPendingCount(): int
    {
        return $this->getStatusCounts()['pending'];
    }

    public function getCompletedCount(): int
    {
        return $this->getStatusCounts()['completed'];
    }

    public function getFailedCount(): int
    {
        return $this->getStatusCounts()['failed'];
    }

    // Polling a cada 10 segundos se houver análises em andamento
    // Aumentado de 5s para 10s para reduzir carga
    public function getPollingInterval(): ?string
    {
        $counts = $this->getStatusCounts();
        $hasActiveAnalyses = ($counts['processing'] + $counts['pending']) > 0;

        return $hasActiveAnalyses ? '10s' : null;
    }

    private function baseQuery(): Builder
    {
        $query = DocumentAnalysis::query()
            ->where('user_id', Auth::id());

        if ($this->numeroProcesso) {
            $query->where('numero_processo', $this->numeroProcesso);
        }

        return $query;
    }

    /**
     * @return array{processing:int,pending:int,completed:int,failed:int}
     */
    private function getStatusCounts(): array
    {
        $cacheKey = sprintf(
            'widgets:document-status:%s:%s',
            Auth::id() ?? 'guest',
            $this->numeroProcesso ?: 'all'
        );

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function (): array {
            $stats = (array) (clone $this->baseQuery())
                ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing")
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->first()
                ?->toArray();

            return [
                'processing' => (int) ($stats['processing'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'completed' => (int) ($stats['completed'] ?? 0),
                'failed' => (int) ($stats['failed'] ?? 0),
            ];
        });
    }
}
