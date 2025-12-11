<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;

class ProcessAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Análise de Processos';

    protected static ?string $title = 'Consulta de Processos E-Proc';

    protected string $view = 'filament.pages.process-analysis';
}
