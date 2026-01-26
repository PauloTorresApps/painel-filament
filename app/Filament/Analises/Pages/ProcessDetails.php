<?php

namespace App\Filament\Analises\Pages;

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

            // Recalcula sequência se não existir (fallback para processos consultados antes desta feature)
            $this->garantirSequenciaAnalise();

            // Debug: Verifica se documentos têm sequencia_analise
            Log::info('📄 Documentos carregados na página', [
                'total_documentos' => count($this->documentos),
                'sample_doc' => !empty($this->documentos) ? [
                    'id' => $this->documentos[0]['idDocumento'] ?? 'N/A',
                    'descricao' => $this->documentos[0]['descricao'] ?? 'N/A',
                    'sequencia_analise' => $this->documentos[0]['sequencia_analise'] ?? 'CAMPO NÃO EXISTE',
                    'keys' => array_keys($this->documentos[0])
                ] : 'Sem documentos'
            ]);
        } else {
            // Fallback para sessão (compatibilidade)
            $this->dadosBasicos = session('dadosBasicos', []);
            $this->movimentos = session('movimentos', []);
            $this->documentos = session('documentos', []);
            $this->numeroProcesso = session('numeroProcesso', '');

            session()->forget(['dadosBasicos', 'movimentos', 'documentos', 'numeroProcesso']);
        }
    }

    /**
     * Garante que todos os documentos têm o campo sequencia_analise
     * Útil para processos consultados antes desta feature ser implementada
     */
    private function garantirSequenciaAnalise(): void
    {
        // Verifica se precisa recalcular checando ambos os arrays
        $precisaRecalcular = false;

        // Verifica documentos em movimentos
        foreach ($this->movimentos as $movimento) {
            foreach ($movimento['documentos'] ?? [] as $doc) {
                if (!isset($doc['sequencia_analise'])) {
                    $precisaRecalcular = true;
                    break 2;
                }
            }
        }

        // Verifica documentos no array principal
        if (!$precisaRecalcular) {
            foreach ($this->documentos as $doc) {
                if (!isset($doc['sequencia_analise'])) {
                    $precisaRecalcular = true;
                    break;
                }
            }
        }

        if (!$precisaRecalcular) {
            Log::info('✅ Todos os documentos já têm sequencia_analise');
            return; // Todos os documentos já têm sequência
        }

        Log::info('⚠️ Recalculando sequência de análise (fallback)');

        // Ordena movimentos por ID
        usort($this->movimentos, function($a, $b) {
            return ((int) ($a['idMovimento'] ?? 999999)) <=> ((int) ($b['idMovimento'] ?? 999999));
        });

        // Cria mapa de sequência
        $sequenciaGlobal = [];
        $sequenciaAtual = 1;

        foreach ($this->movimentos as $movimento) {
            $idsVinculados = $movimento['idDocumentoVinculado'] ?? [];

            if (!is_array($idsVinculados)) {
                $idsVinculados = [$idsVinculados];
            }

            foreach ($idsVinculados as $idDoc) {
                $sequenciaGlobal[$idDoc] = $sequenciaAtual;
                $sequenciaAtual++;
            }
        }

        // Aplica sequência aos documentos em movimentos
        foreach ($this->movimentos as &$movimento) {
            foreach ($movimento['documentos'] ?? [] as &$doc) {
                $idDoc = $doc['idDocumento'] ?? null;
                $doc['sequencia_analise'] = $sequenciaGlobal[$idDoc] ?? 999999;
            }
        }
        unset($movimento, $doc);

        // Aplica sequência aos documentos no array principal
        foreach ($this->documentos as &$doc) {
            $idDoc = $doc['idDocumento'] ?? null;
            $doc['sequencia_analise'] = $sequenciaGlobal[$idDoc] ?? 999999;
        }
        unset($doc);

        Log::info('✅ Sequência recalculada com sucesso (fallback)', [
            'total_documentos_sequenciados' => count($sequenciaGlobal),
            'sequencia_maxima' => $sequenciaAtual - 1
        ]);
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
                        ? route('filament.analises.resources.document-analyses.view', $ultimaAnalise)
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
                ->modalDescription('Todos os documentos não-mídia serão enviados para análise pela IA. Esta operação pode levar alguns minutos.')
                ->action(function () {
                    $this->enviarParaAnalise();
                })
                ->visible(fn () => !empty($this->documentos))
                ->disabled(function () {
                    // Desabilita se já existe análise em andamento
                    return \App\Models\DocumentAnalysis::where('user_id', auth()->id())
                        ->where('numero_processo', $this->numeroProcesso)
                        ->where('status', 'processing')
                        ->exists();
                }),

            \Filament\Actions\Action::make('voltar')
                ->label('Voltar')
                ->color('gray')
                ->icon('heroicon-m-arrow-left')
                ->url(route('filament.analises.pages.process-analysis')),
        ];
    }

    /**
     * Envia todos os documentos para análise
     */
    public function enviarParaAnalise(): void
    {
        try {
            // Verifica se já existe uma análise em andamento para este processo
            $analiseEmAndamento = \App\Models\DocumentAnalysis::where('user_id', auth()->user()->id)
                ->where('numero_processo', $this->numeroProcesso)
                ->where('status', 'processing')
                ->exists();

            if ($analiseEmAndamento) {
                \Filament\Notifications\Notification::make()
                    ->title('⚠️ Análise Já em Andamento')
                    ->body('Já existe uma análise em processamento para este processo. Aguarde a conclusão ou cancele a análise anterior antes de iniciar uma nova.')
                    ->warning()
                    ->persistent()
                    ->send();

                Log::info('Tentativa de análise duplicada bloqueada', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso
                ]);

                return;
            }

            // Busca o prompt padrão do sistema (global)
            $promptPadrao = \App\Models\AiPrompt::where('system_id', 1) // system_id 1 para análise de processos
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

            if (!$promptPadrao) {
                \Filament\Notifications\Notification::make()
                    ->title('⚠️ Prompt Não Configurado')
                    ->body('O sistema não possui um prompt padrão configurado para análise de processos. Entre em contato com o administrador do sistema.')
                    ->danger()
                    ->persistent()
                    ->send();

                Log::warning('Tentativa de análise sem prompt padrão configurado no sistema', [
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

            // Filtra apenas documentos que não sejam vídeos
            $documentosParaAnalise = collect($this->documentos)->filter(function ($doc) {
                $descricao = strtolower($doc['descricao'] ?? '');
                $mimeType = strtolower($doc['mimetype'] ?? '');

                // 1. Rejeita documentos HTML (atos ordinatórios sem conteúdo real)
                if ($mimeType === 'text/html' || str_contains($mimeType, 'html')) {
                    Log::debug('Documento rejeitado: HTML/sem conteúdo', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'mimeType' => $mimeType
                    ]);
                    return false;
                }

                // 2. Rejeita APENAS vídeos (mantém imagens e outros formatos)
                $extensoesVideo = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'mpeg', 'mpg', '3gp', 'm4v'];

                // Rejeita por mimetype de vídeo
                if (str_starts_with($mimeType, 'video/')) {
                    Log::debug('Documento rejeitado: vídeo (mimetype)', [
                        'id' => $doc['idDocumento'] ?? 'sem_id',
                        'descricao' => $doc['descricao'] ?? 'sem_descricao',
                        'mimeType' => $mimeType
                    ]);
                    return false;
                }

                // Rejeita por extensão de vídeo
                foreach ($extensoesVideo as $ext) {
                    if (str_ends_with($descricao, '.' . $ext)) {
                        Log::debug('Documento rejeitado: vídeo (extensão)', [
                            'id' => $doc['idDocumento'] ?? 'sem_id',
                            'descricao' => $doc['descricao'] ?? 'sem_descricao',
                            'extensao' => $ext
                        ]);
                        return false;
                    }
                }

                // Documento aprovado! (aceita PDFs, imagens, documentos Office, etc.)
                Log::info('Documento APROVADO para análise', [
                    'id' => $doc['idDocumento'] ?? 'sem_id',
                    'descricao' => $doc['descricao'] ?? 'sem_descricao',
                    'mimeType' => $mimeType
                ]);

                return true;
            })
            // ORDENA DOCUMENTOS POR SEQUÊNCIA GLOBAL DE ANÁLISE
            // A sequência é calculada no EprocController baseada em:
            // 1. Ordem cronológica dos eventos (idMovimento)
            // 2. Ordem dos documentos vinculados (idDocumentoVinculado) dentro de cada evento
            // Resultado: 1, 2, 3... N (sequência contínua do primeiro ao último documento)
            ->sortBy(function ($doc) {
                return (int) ($doc['sequencia_analise'] ?? 999999);
            })
            ->values()
            ->toArray();

            // Log da ordem final de análise
            if (!empty($documentosParaAnalise)) {
                Log::info('📋 ORDEM FINAL DE ANÁLISE DOS DOCUMENTOS', [
                    'total_documentos' => count($documentosParaAnalise),
                    'ordem_analise' => collect($documentosParaAnalise)->map(function ($doc) {
                        return [
                            'sequencia_global' => $doc['sequencia_analise'] ?? 'N/A',
                            'evento_id' => $doc['idMovimento'] ?? 'N/A',
                            'documento_id' => $doc['idDocumento'] ?? 'N/A',
                            'descricao' => $doc['descricao'] ?? 'Sem descrição',
                        ];
                    })->toArray()
                ]);
            }

            if (empty($documentosParaAnalise)) {
                $totalDocumentos = count($this->documentos);

                // Conta motivos de exclusão
                $htmlSemConteudo = collect($this->documentos)->filter(function($doc) {
                    $mimeType = strtolower($doc['mimetype'] ?? '');
                    return $mimeType === 'text/html' || str_contains($mimeType, 'html');
                })->count();

                $videos = collect($this->documentos)->filter(function($doc) {
                    $mimeType = strtolower($doc['mimetype'] ?? '');
                    if ($mimeType === 'text/html' || str_contains($mimeType, 'html')) return false;

                    $descricao = strtolower($doc['descricao'] ?? '');
                    $extensoesVideo = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'mpeg', 'mpg', '3gp', 'm4v'];

                    if (str_starts_with($mimeType, 'video/')) {
                        return true;
                    }

                    foreach ($extensoesVideo as $ext) {
                        if (str_ends_with($descricao, '.' . $ext)) {
                            return true;
                        }
                    }

                    return false;
                })->count();

                $detalhes = [];
                if ($htmlSemConteudo > 0) $detalhes[] = "{$htmlSemConteudo} sem conteúdo disponível (HTML)";
                if ($videos > 0) $detalhes[] = "{$videos} arquivo(s) de vídeo";

                $mensagemDetalhes = !empty($detalhes)
                    ? "Motivos de exclusão: " . implode(", ", $detalhes) . "."
                    : "Todos os documentos foram filtrados.";

                \Filament\Notifications\Notification::make()
                    ->title('📋 Nenhum Documento Elegível para Análise')
                    ->body("Total: {$totalDocumentos} documento(s). {$mensagemDetalhes} Documentos em vídeo não podem ser analisados. Outros formatos (PDF, imagens, documentos Office, etc.) são aceitos.")
                    ->warning()
                    ->persistent()
                    ->send();

                Log::warning('Nenhum documento elegível para análise', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso,
                    'total_documentos' => $totalDocumentos,
                    'html_sem_conteudo' => $htmlSemConteudo,
                    'videos' => $videos,
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
                $promptPadrao->deep_thinking_enabled ?? true, // Modo de pensamento profundo (DeepSeek)
                \App\Models\JudicialUser::find($this->judicialUserId)->user_login,
                $this->senha,
                $this->judicialUserId,
                $promptPadrao->analysis_strategy ?? 'evolutionary' // Estratégia de análise (hierarchical ou evolutionary)
            );

            $totalDocs = count($documentosParaAnalise);
            $providerName = match($promptPadrao->ai_provider ?? 'gemini') {
                'gemini' => 'Google Gemini',
                'deepseek' => 'DeepSeek',
                'openai' => 'OpenAI',
                default => 'IA'
            };

            \Filament\Notifications\Notification::make()
                ->title('🚀 Análise Iniciada')
                ->body("**Etapa 1/2:** Baixando {$totalDocs} documento(s) do e-Proc...\n\n**Etapa 2/2:** Em seguida, os documentos serão analisados pela {$providerName}.\n\n⏱️ Este processo pode levar alguns minutos. Você será notificado quando concluir.\n\nAcompanhe o progresso no painel acima.")
                ->info()
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
