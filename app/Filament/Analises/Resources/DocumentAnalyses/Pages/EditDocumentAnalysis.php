<?php

namespace App\Filament\Analises\Resources\DocumentAnalyses\Pages;

use App\Filament\Analises\Resources\DocumentAnalyses\DocumentAnalysisResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentAnalysis extends EditRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
