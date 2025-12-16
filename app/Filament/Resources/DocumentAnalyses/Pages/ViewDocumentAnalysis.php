<?php

namespace App\Filament\Resources\DocumentAnalyses\Pages;

use App\Filament\Resources\DocumentAnalyses\DocumentAnalysisResource;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Response;

class ViewDocumentAnalysis extends ViewRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_pdf')
                ->label('Baixar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn () => $this->record->status === 'completed' && !empty($this->record->ai_analysis))
                ->action(function () {
                    $analysis = $this->record;

                    // Gera o PDF
                    $pdf = Pdf::loadView('pdf.document-analysis', [
                        'analysis' => $analysis
                    ]);

                    // Nome do arquivo
                    $filename = 'analise_' . str_replace(['/', '.', '-'], '_', $analysis->numero_processo) . '_' . $analysis->id . '.pdf';

                    // Retorna o PDF para download
                    return Response::streamDownload(function() use ($pdf) {
                        echo $pdf->output();
                    }, $filename);
                }),

            EditAction::make(),
        ];
    }
}
