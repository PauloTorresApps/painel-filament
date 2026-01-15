<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;

class ContractAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';
    protected string $view = 'filament.pages.contract-analysis';
    protected static ?string $navigationLabel = 'Análise de Contratos';
    protected static ?string $title = 'Análise de Contratos';

}
