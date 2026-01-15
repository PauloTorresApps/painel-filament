<?php

namespace App\Filament\Resources\ContractAnalysis\Pages;

use App\Filament\Resources\ContractAnalysis\ContractAnalysisResource;
use Filament\Resources\Pages\ListRecords;

class ListContractAnalyses extends ListRecords
{
    protected static string $resource = ContractAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('nova_analise')
                ->label('Nova Análise')
                ->icon('heroicon-o-plus')
                ->url(route('filament.admin.pages.contract-analysis')),
        ];
    }
}
