<?php

namespace App\Filament\Analises\Resources\DocumentAnalyses\Pages;

use App\Filament\Analises\Resources\DocumentAnalyses\DocumentAnalysisResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentAnalyses extends ListRecords
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
