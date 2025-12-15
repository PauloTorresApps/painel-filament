<?php

namespace App\Filament\Resources\DocumentAnalyses\Pages;

use App\Filament\Resources\DocumentAnalyses\DocumentAnalysisResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDocumentAnalysis extends ViewRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
