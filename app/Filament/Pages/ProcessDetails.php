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

    // Widget removido - agora exibe apenas no Dashboard

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
            // Botão para ver última análise
            \Filament\Actions\Action::make('ver_ultima_analise')
                ->label('Ver Última Análise')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->url(function () {
                    $ultimaAnalise = \App\Models\DocumentAnalysis::where('user_id', auth()->id())
                        ->where('numero_processo', $this->numeroProcesso)
                        ->where('status', 'completed')
                        ->latest()
                        ->first();

                    return $ultimaAnalise
                        ? route('filament.admin.resources.document-analyses.view', $ultimaAnalise)
                        : null;
                })
                ->visible(function () {
                    return \App\Models\DocumentAnalysis::where('user_id', auth()->id())
                        ->where('numero_processo', $this->numeroProcesso)
                        ->where('status', 'completed')
                        ->exists();
                }),

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
                    ->title('⚠️ Prompt Não Configurado')
                    ->body('Você precisa configurar um prompt padrão antes de enviar documentos para análise. Acesse "Prompts de IA" no menu lateral e crie um prompt padrão para o sistema de análise de processos.')
                    ->danger()
                    ->persistent()
                    ->send();

                Log::warning('Tentativa de análise sem prompt padrão', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso
                ]);

                return;
            }

            // Log dos documentos antes do filtro para debug
            Log::info('Documentos disponíveis para filtro', [
                'total' => count($this->documentos),
                'documentos' => collect($this->documentos)->map(function($doc) {
                    return [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'nivelSigilo' => $doc['nivelSigilo'] ?? 'null',
                        'mimetype' => $doc['mimetype'] ?? 'null',
                    ];
                })->toArray()
            ]);

            // Filtra apenas documentos não sigilosos, não-mídia e com conteúdo disponível
            $documentosParaAnalise = collect($this->documentos)->filter(function ($doc) {
                $nivelSigilo = $doc['nivelSigilo'] ?? 0;
                $descricao = strtolower($doc['descricao'] ?? '');
                $mimeType = strtolower($doc['mimetype'] ?? '');

                // 1. Rejeita sigilosos
                if ($nivelSigilo > 0) {
                    Log::debug('Documento rejeitado: sigiloso', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'nivelSigilo' => $nivelSigilo
                    ]);
                    return false;
                }

                // 2. Rejeita documentos HTML (atos ordinatórios sem conteúdo real)
                if ($mimeType === 'text/html' || str_contains($mimeType, 'html')) {
                    Log::debug('Documento rejeitado: HTML/sem conteúdo', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'mimeType' => $mimeType
                    ]);
                    return false;
                }

                // 3. Rejeita mídias (imagens, vídeos)
                $extensoesMedia = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'];

                if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/')) {
                    Log::debug('Documento rejeitado: mídia (mimetype)', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'mimeType' => $mimeType
                    ]);
                    return false;
                }

                foreach ($extensoesMedia as $ext) {
                    if (str_ends_with($descricao, '.' . $ext)) {
                        Log::debug('Documento rejeitado: mídia (extensão)', [
                            'id' => $doc['idDocumento'] ?? 'sem_id',
                            'descricao' => $doc['descricao'] ?? 'sem_descricao',
                            'extensao' => $ext
                        ]);
                        return false;
                    }
                }

                // 4. Aceita apenas documentos com mimetype vazio ou application/pdf
                $isPdfValido = empty($mimeType) ||
                               $mimeType === 'application/pdf' ||
                               str_starts_with($mimeType, 'application/pdf');

                if (!$isPdfValido) {
                    Log::debug('Documento rejeitado: mimetype inválido', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'mimeType' => $mimeType
                    ]);
                    return false;
                }

                // Documento aprovado!
                Log::info('Documento APROVADO para análise', [
                    'id' => $doc['idDocumento'] ?? 'sem_id',
                    'descricao' => $doc['descricao'] ?? 'sem_descricao',
                    'mimeType' => $mimeType
                ]);

                return true;
            })->values()->toArray();

            if (empty($documentosParaAnalise)) {
                $totalDocumentos = count($this->documentos);

                // Conta motivos de exclusão
                $sigilosos = collect($this->documentos)->filter(fn($d) => ($d['nivelSigilo'] ?? 0) > 0)->count();

                $htmlSemConteudo = collect($this->documentos)->filter(function($doc) {
                    if (($doc['nivelSigilo'] ?? 0) > 0) return false;
                    $mimeType = strtolower($doc['mimetype'] ?? '');
                    return $mimeType === 'text/html' || str_contains($mimeType, 'html');
                })->count();

                $midias = collect($this->documentos)->filter(function($doc) {
                    if (($doc['nivelSigilo'] ?? 0) > 0) return false;
                    $mimeType = strtolower($doc['mimetype'] ?? '');
                    if ($mimeType === 'text/html' || str_contains($mimeType, 'html')) return false;

                    $descricao = strtolower($doc['descricao'] ?? '');
                    $extensoesMedia = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'];

                    if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/')) {
                        return true;
                    }

                    foreach ($extensoesMedia as $ext) {
                        if (str_ends_with($descricao, '.' . $ext)) {
                            return true;
                        }
                    }

                    return false;
                })->count();

                $detalhes = [];
                if ($sigilosos > 0) $detalhes[] = "{$sigilosos} sigiloso(s)";
                if ($htmlSemConteudo > 0) $detalhes[] = "{$htmlSemConteudo} sem conteúdo disponível (HTML)";
                if ($midias > 0) $detalhes[] = "{$midias} arquivo(s) de mídia";

                $mensagemDetalhes = !empty($detalhes)
                    ? "Motivos de exclusão: " . implode(", ", $detalhes) . "."
                    : "Todos os documentos foram filtrados.";

                \Filament\Notifications\Notification::make()
                    ->title('📋 Nenhum Documento Elegível para Análise')
                    ->body("Total: {$totalDocumentos} documento(s). {$mensagemDetalhes} Apenas documentos PDF com conteúdo disponível e não-sigilosos podem ser analisados.")
                    ->warning()
                    ->persistent()
                    ->send();

                Log::warning('Nenhum documento elegível para análise', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso,
                    'total_documentos' => $totalDocumentos,
                    'sigilosos' => $sigilosos,
                    'html_sem_conteudo' => $htmlSemConteudo,
                    'midias' => $midias,
                    'detalhe_mensagem' => $mensagemDetalhes
                ]);

                return;
            }

            // Dispara o Job com o provider de IA selecionado
            \App\Jobs\AnalyzeProcessDocuments::dispatch(
                auth()->user()->id,
                $this->numeroProcesso,
                $documentosParaAnalise,
                $this->dadosBasicos,
                $promptPadrao->content,
                $promptPadrao->ai_provider ?? 'gemini', // Provider de IA (gemini ou deepseek)
                \App\Models\JudicialUser::find($this->judicialUserId)->user_login,
                $this->senha,
                $this->judicialUserId
            );

            \Filament\Notifications\Notification::make()
                ->title('✅ Análise Iniciada com Sucesso')
                ->body(count($documentosParaAnalise) . ' documento(s) foram enviados para análise. Acompanhe o progresso no widget "Status das Análises de IA" acima.')
                ->success()
                ->persistent()
                ->send();

            Log::info('Análise de documentos iniciada', [
                'user_id' => auth()->user()->id,
                'numero_processo' => $this->numeroProcesso,
                'total_documentos' => count($documentosParaAnalise)
            ]);

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('❌ Erro ao Enviar para Análise')
                ->body('Erro: ' . $e->getMessage() . '. Verifique os logs para mais detalhes ou entre em contato com o suporte.')
                ->danger()
                ->persistent()
                ->send();

            Log::error('Erro ao enviar documentos para análise', [
                'user_id' => auth()->id(),
                'numero_processo' => $this->numeroProcesso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
