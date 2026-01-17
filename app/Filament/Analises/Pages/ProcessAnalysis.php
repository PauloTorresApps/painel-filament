<?php

namespace App\Filament\Analises\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;
use Illuminate\Support\Facades\Auth;

class ProcessAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Análise de Processos';

    protected static ?string $title = 'Consulta de Processos E-Proc';

    protected static UnitEnum|string|null $navigationGroup = 'Processos';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.process-analysis';

    /**
     * Controle de acesso - apenas Admin, Manager ou Analista de Processo
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager', 'Analista de Processo']);
    }
}
