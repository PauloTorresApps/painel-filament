<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class ProcessDetails extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Detalhes do Processo';

    protected string $view = 'filament.pages.process-details';

    public array $dadosBasicos = [];
    public array $movimentos = [];
    public array $documentos = [];
    public string $numeroProcesso = '';
    public ?int $judicialUserId = null;
    public ?string $senha = null;

    public function mount(): void
    {
        // Tenta pegar a chave do cache da query string
        $cacheKey = request()->query('key');

        if ($cacheKey && cache()->has($cacheKey)) {
            $data = cache()->get($cacheKey);
            $this->dadosBasicos = $data['dadosBasicos'] ?? [];
            $this->movimentos = $data['movimentos'] ?? [];
            $this->documentos = $data['documentos'] ?? [];
            $this->numeroProcesso = $data['numeroProcesso'] ?? '';
            $this->judicialUserId = $data['judicial_user_id'] ?? null;
            $this->senha = $data['senha'] ?? null;
        } else {
            // Fallback para sessão (compatibilidade)
            $this->dadosBasicos = session('dadosBasicos', []);
            $this->movimentos = session('movimentos', []);
            $this->documentos = session('documentos', []);
            $this->numeroProcesso = session('numeroProcesso', '');

            session()->forget(['dadosBasicos', 'movimentos', 'documentos', 'numeroProcesso']);
        }
    }

    public function getTitle(): string
    {
        return $this->numeroProcesso ?: 'Detalhes do Processo';
    }

    public function getHeading(): string
    {
        return $this->numeroProcesso ?: 'Detalhes do Processo';
    }

    public function getSubheading(): ?string
    {
        if (!empty($this->dadosBasicos['dataAjuizamento'])) {
            return 'Ajuizado em ' . \Carbon\Carbon::parse($this->dadosBasicos['dataAjuizamento'])->format('d/m/Y');
        }
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('analisar_documentos')
                ->label('Enviar todos os documentos para análise')
                ->icon('heroicon-m-document-magnifying-glass')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirmar Análise de Documentos')
                ->modalDescription('Todos os documentos não sigilosos e não-mídia serão enviados para análise pela IA. Esta operação pode levar alguns minutos.')
                ->action(function () {
                    $this->enviarParaAnalise();
                })
                ->visible(fn () => !empty($this->documentos)),

            \Filament\Actions\Action::make('voltar')
                ->label('Voltar')
                ->color('gray')
                ->icon('heroicon-m-arrow-left')
                ->url(route('filament.admin.pages.process-analysis')),
        ];
    }

    /**
     * Envia todos os documentos para análise
     */
    public function enviarParaAnalise(): void
    {
        try {
            // Busca o prompt padrão do usuário para o sistema atual
            $promptPadrao = \App\Models\AiPrompt::where('user_id', auth()->user()->id)
                ->where('system_id', 1) // Assumindo system_id 1 para análise de processos
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$promptPadrao) {
                \Filament\Notifications\Notification::make()
                    ->title('Prompt não encontrado')
                    ->body('Você precisa configurar um prompt padrão antes de enviar documentos para análise.')
                    ->danger()
                    ->send();
                return;
            }

            // Filtra apenas documentos não sigilosos e não-mídia
            $documentosParaAnalise = collect($this->documentos)->filter(function ($doc) {
                $nivelSigilo = $doc['nivelSigilo'] ?? 0;

                // Verifica se é arquivo de mídia
                $descricao = strtolower($doc['descricao'] ?? '');
                $mimeType = strtolower($doc['mimetype'] ?? '');

                $extensoesMedia = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'];
                $isArquivoMedia = false;

                if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/')) {
                    $isArquivoMedia = true;
                }

                foreach ($extensoesMedia as $ext) {
                    if (str_ends_with($descricao, '.' . $ext)) {
                        $isArquivoMedia = true;
                        break;
                    }
                }

                return $nivelSigilo == 0 && !$isArquivoMedia;
            })->values()->toArray();

            if (empty($documentosParaAnalise)) {
                \Filament\Notifications\Notification::make()
                    ->title('Nenhum documento elegível')
                    ->body('Não há documentos não-sigilosos e não-mídia para analisar.')
                    ->warning()
                    ->send();
                return;
            }

            // Dispara o Job
            \App\Jobs\AnalyzeProcessDocuments::dispatch(
                auth()->user()->id,
                $this->numeroProcesso,
                $documentosParaAnalise,
                $this->dadosBasicos,
                $promptPadrao->content,
                \App\Models\JudicialUser::find($this->judicialUserId)->user_login,
                $this->senha,
                $this->judicialUserId
            );

            \Filament\Notifications\Notification::make()
                ->title('Análise Iniciada')
                ->body(count($documentosParaAnalise) . ' documento(s) foram enviados para análise. Você receberá notificações sobre o progresso.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Erro ao enviar para análise')
                ->body($e->getMessage())
                ->danger()
                ->send();

            Log::error('Erro ao enviar documentos para análise', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
