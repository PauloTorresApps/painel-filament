<?php

namespace App\Filament\Analises\Resources\DocumentAnalyses\Pages;

use App\Filament\Analises\Resources\DocumentAnalyses\DocumentAnalysisResource;
use App\Jobs\DispatchMapPhaseJob;
use App\Models\DocumentMicroAnalysis;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

            Action::make('retry_processing')
                ->label('Reprocessar')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, ['failed', 'processing', 'cancelled']))
                ->requiresConfirmation()
                ->modalHeading('Reprocessar Análise')
                ->modalDescription('Isso irá reiniciar o processamento desta análise. Todas as análises parciais serão refeitas. Deseja continuar?')
                ->modalSubmitActionLabel('Sim, reprocessar')
                ->action(function () {
                    $analysis = $this->record;

                    // Reset micro analyses to pending
                    DocumentMicroAnalysis::where('document_analysis_id', $analysis->id)
                        ->update([
                            'status' => 'pending',
                            'error_message' => null,
                        ]);

                    // Reset analysis status
                    $analysis->update([
                        'status' => 'processing',
                        'error_message' => null,
                        'ai_analysis' => null,
                        'current_phase' => 'map',
                        'processed_documents_count' => 0,
                        'progress_message' => 'Reiniciando processamento...',
                    ]);

                    // Get job parameters
                    $jobParams = $analysis->job_parameters ?? [];

                    // Dispatch the job
                    DispatchMapPhaseJob::dispatch(
                        $analysis->id,
                        $jobParams['ai_provider'] ?? 'openrouter',
                        $jobParams['deep_thinking_enabled'] ?? true,
                        [
                            'numero_processo' => $analysis->numero_processo,
                            'classe_processual' => $analysis->classe_processual ?? 'Não informada',
                            'assuntos' => $analysis->assuntos ?? 'Não informados',
                        ],
                        $jobParams['ai_model_id'] ?? null,
                        $analysis->user_id,
                        'auto'
                    );

                    Notification::make()
                        ->title('Processamento reiniciado')
                        ->body('A análise foi colocada na fila para reprocessamento.')
                        ->success()
                        ->send();

                    // Refresh the record
                    $this->refreshFormData(['status', 'progress_message', 'error_message']);
                }),

            DeleteAction::make()
                ->label('Excluir')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Excluir Análise')
                ->modalDescription('Esta ação é irreversível. A análise e todos os dados relacionados serão permanentemente excluídos. Deseja continuar?')
                ->modalSubmitActionLabel('Sim, excluir')
                ->before(function () {
                    // Delete related micro analyses first
                    DocumentMicroAnalysis::where('document_analysis_id', $this->record->id)->delete();
                })
                ->successRedirectUrl(DocumentAnalysisResource::getUrl('index')),

            EditAction::make(),
        ];
    }
}
