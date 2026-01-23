<?php

namespace App\Filament\Traits;

use App\Jobs\GenerateInfographicJob;
use App\Models\AiPrompt;
use App\Models\ContractAnalysis;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

trait HasInfographicActions
{
    /**
     * Cria a action para gerar infográfico
     */
    protected function makeGenerateInfographicAction(): Action
    {
        return Action::make('generateInfographic')
            ->label(fn ($record) => $record->isInfographicProcessing() ? 'Gerando...' : 'Gerar Infográfico')
            ->icon('heroicon-o-chart-bar')
            ->color('success')
            ->visible(fn ($record) => $record->canGenerateInfographic() && !$record->isInfographicCompleted())
            ->disabled(fn ($record) => $record->isInfographicProcessing())
            ->action(fn ($record) => $this->dispatchInfographicJob($record));
    }

    /**
     * Cria a action para tentar novamente gerar infográfico
     */
    protected function makeRetryInfographicAction(): Action
    {
        return Action::make('retryInfographic')
            ->label('Tentar Novamente')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->action(fn ($record) => $this->dispatchInfographicJob($record, true));
    }

    /**
     * Dispara o job de geração de infográfico com verificação de prompts
     */
    protected function dispatchInfographicJob(ContractAnalysis $record, bool $isRetry = false): void
    {
        // Verifica se os prompts necessários existem
        $promptCheck = AiPrompt::checkInfographicPromptsExist();

        if (!$promptCheck['exists']) {
            $missingList = implode(', ', $promptCheck['missing']);

            Notification::make()
                ->title('Configuração Incompleta')
                ->body("Os seguintes prompts não estão configurados: {$missingList}. Por favor, entre em contato com o administrador do sistema.")
                ->danger()
                ->persistent()
                ->send();

            Log::warning($isRetry ? 'Tentativa de reprocessar infográfico sem prompts configurados' : 'Tentativa de gerar infográfico sem prompts configurados', [
                'analysis_id' => $record->id,
                'missing_prompts' => $promptCheck['missing'],
            ]);

            return;
        }

        GenerateInfographicJob::dispatch($record->id);

        Notification::make()
            ->title('Gerando Infográfico')
            ->body($isRetry ? 'O infográfico está sendo gerado novamente.' : 'O infográfico está sendo gerado. Você será notificado quando concluir.')
            ->success()
            ->send();

        if (!$isRetry) {
            Log::info('Geração de infográfico iniciada via histórico', [
                'analysis_id' => $record->id,
            ]);
        }
    }
}
