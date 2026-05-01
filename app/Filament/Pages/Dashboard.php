<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Painel de Controle';

    protected static ?string $navigationLabel = 'Painel de Controle';

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\DocumentAnalysisStatusWidget::class,
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return $this->getWidgets();
    }
}
