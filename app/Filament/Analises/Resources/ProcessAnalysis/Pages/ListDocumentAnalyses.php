<?php

namespace App\Filament\Analises\Resources\ProcessAnalysis\Pages;

use App\Filament\Analises\Resources\ProcessAnalysis\DocumentAnalysisResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListDocumentAnalyses extends ListRecords
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('novaAnalise')
                ->label('Nova Análise')
                ->icon('heroicon-o-plus')
                ->url(route('filament.analises.pages.process-analysis')),
        ];
    }
}
