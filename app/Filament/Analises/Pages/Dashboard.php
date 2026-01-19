<?php

namespace App\Filament\Analises\Pages;

use App\Filament\Analises\Widgets\ContractAnalysisStatsWidget;
use App\Filament\Analises\Widgets\ProcessAnalysisStatsWidget;
use App\Filament\Analises\Widgets\RecentContractAnalysesWidget;
use App\Filament\Analises\Widgets\RecentProcessAnalysesWidget;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Home;

    protected static ?string $navigationLabel = 'Painel de Controle';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.analises.pages.dashboard';

    public string $activeTab = 'processos';

    public function mount(): void
    {
        $user = Auth::user();

        if ($user && $user->default_dashboard_tab) {
            $this->activeTab = $user->default_dashboard_tab;
        } else {
            // Define aba padrão baseada nas permissões
            if ($this->canViewProcesses()) {
                $this->activeTab = 'processos';
            } elseif ($this->canViewContracts()) {
                $this->activeTab = 'contratos';
            }
        }
    }

    public function getTitle(): string
    {
        return 'Painel de Controle';
    }

    public function canViewProcesses(): bool
    {
        $user = Auth::user();
        return $user && $user->hasAnyRole(['Admin', 'Manager', 'Default', 'Analista de Processo']);
    }

    public function canViewContracts(): bool
    {
        $user = Auth::user();
        return $user && $user->hasAnyRole(['Admin', 'Manager', 'Default', 'Analista de Contrato']);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setDefaultTab(string $tab): void
    {
        $user = Auth::user();

        if ($user) {
            $user->update(['default_dashboard_tab' => $tab]);

            Notification::make()
                ->title('Aba padrão definida')
                ->body('A aba "' . $this->getTabLabel($tab) . '" será carregada por padrão.')
                ->success()
                ->send();
        }
    }

    public function getTabLabel(string $tab): string
    {
        return match ($tab) {
            'processos' => 'Análises de Processos',
            'contratos' => 'Análises de Contratos',
            default => $tab,
        };
    }

    public function getProcessWidgets(): array
    {
        return [
            ProcessAnalysisStatsWidget::class,
            RecentProcessAnalysesWidget::class,
        ];
    }

    public function getContractWidgets(): array
    {
        return [
            ContractAnalysisStatsWidget::class,
            RecentContractAnalysesWidget::class,
        ];
    }

    public function isDefaultTab(string $tab): bool
    {
        $user = Auth::user();
        return $user && $user->default_dashboard_tab === $tab;
    }
}
