<?php

namespace App\Filament\Analises\Resources\DocumentAnalyses\Pages;

use App\Filament\Analises\Resources\DocumentAnalyses\DocumentAnalysisResource;
use App\Jobs\DispatchMapPhaseJob;
use App\Jobs\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\On;

class ViewDocumentAnalysis extends ViewRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    /**
     * Polling automático enquanto a análise estiver em progresso
     */
    public function getPollingInterval(): ?string
    {
        // Polling de 5 segundos quando não está concluído
        if ($this->record && $this->record->status !== 'completed') {
            return '5s';
        }

        return null;
    }

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

            Action::make('continue_analysis')
                ->label('Continuar Análise')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(function () {
                    // Visível apenas se falhou/congelou e fase MAP está completa
                    if (!in_array($this->record->status, ['failed', 'processing'])) {
                        return false;
                    }

                    // Verifica se há micro-análises MAP completadas
                    $stats = $this->record->getMicroAnalysisStats();
                    $mapCompleted = $stats['map_completed'] ?? 0;

                    // Precisa ter pelo menos 1 micro-análise MAP completa
                    // E a fase MAP deve estar completa (não ter pendentes/processando)
                    return $mapCompleted > 0 && $this->record->isMapPhaseComplete();
                })
                ->requiresConfirmation()
                ->modalHeading('Continuar Análise')
                ->modalDescription(function () {
                    $stats = $this->record->getMicroAnalysisStats();
                    $mapCompleted = $stats['map_completed'] ?? 0;
                    $total = $this->record->total_documents ?? 0;
                    $failed = $stats['failed'] ?? 0;

                    $msg = "Esta ação irá continuar a análise a partir da fase de consolidação (REDUCE).\n\n";
                    $msg .= "Documentos analisados: {$mapCompleted}/{$total}";

                    if ($failed > 0) {
                        $msg .= "\nDocumentos com falha: {$failed} (serão ignorados na consolidação)";
                    }

                    return $msg;
                })
                ->modalSubmitActionLabel('Sim, continuar')
                ->action(function () {
                    $analysis = $this->record;

                    // Verifica se fase MAP está completa
                    if (!$analysis->isMapPhaseComplete()) {
                        Notification::make()
                            ->title('Não é possível continuar')
                            ->body('A análise individual dos documentos ainda não foi concluída.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Verifica se há pelo menos uma micro-análise completa
                    $completedMicros = $analysis->microAnalyses()
                        ->mapLevel()
                        ->completed()
                        ->count();

                    if ($completedMicros === 0) {
                        Notification::make()
                            ->title('Não é possível continuar')
                            ->body('Nenhum documento foi analisado com sucesso.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Atualiza status para processando
                    $analysis->update([
                        'status' => 'processing',
                        'error_message' => null,
                        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
                        'progress_message' => 'Retomando consolidação...',
                    ]);

                    // Obtém parâmetros do job
                    $jobParams = $analysis->job_parameters ?? [];

                    // Dispara fase REDUCE
                    ReduceDocumentAnalysisJob::dispatch(
                        $analysis->id,
                        $jobParams['ai_provider'] ?? 'openrouter',
                        $jobParams['deep_thinking_enabled'] ?? true,
                        $jobParams['promptTemplate'] ?? '',
                        $jobParams['ai_model_id'] ?? null,
                        1 // Começa do nível 1
                    )->onQueue('analysis');

                    Notification::make()
                        ->title('Análise retomada')
                        ->body("Consolidação de {$completedMicros} documento(s) iniciada.")
                        ->success()
                        ->send();

                    // Refresh the record
                    $this->refreshFormData(['status', 'progress_message', 'current_phase']);
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
