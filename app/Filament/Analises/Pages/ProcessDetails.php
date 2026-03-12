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
    public ?string $chave = null;
    public array $selectedDocuments = [];

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
            $this->chave = $data['chave'] ?? null;

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

        $this->initSelectedDocuments();
    }

    /**
     * Inicializa a seleção padrão dos documentos.
     * PDF e HTML iniciam selecionados; arquivos de mídia (imagem, vídeo, áudio) iniciam desmarcados.
     */
    private function initSelectedDocuments(): void
    {
        $extensoesMedia = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'mp3', 'wav', 'ogg', 'aac',
                           'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'];

        foreach ($this->documentos as $doc) {
            $idDocumento = $doc['idDocumento'] ?? null;
            if ($idDocumento === null) {
                continue;
            }

            $descricao = strtolower($doc['descricao'] ?? '');
            $mimeType = strtolower($doc['conteudo']['mimetype'] ?? ($doc['mimetype'] ?? ''));

            $isMedia = str_starts_with($mimeType, 'image/')
                    || str_starts_with($mimeType, 'video/')
                    || str_starts_with($mimeType, 'audio/');

            if (!$isMedia) {
                foreach ($extensoesMedia as $ext) {
                    if (str_ends_with($descricao, '.' . $ext)) {
                        $isMedia = true;
                        break;
                    }
                }
            }

            $this->selectedDocuments[$idDocumento] = !$isMedia;
        }
    }

    /**
     * Seleciona todos os documentos para análise.
     */
    public function selectAll(): void
    {
        foreach ($this->selectedDocuments as $id => $_) {
            $this->selectedDocuments[$id] = true;
        }
    }

    /**
     * Desmarca todos os documentos da análise.
     */
    public function deselectAll(): void
    {
        foreach ($this->selectedDocuments as $id => $_) {
            $this->selectedDocuments[$id] = false;
        }
    }

    /**
     * Seleciona todos os documentos de um evento (movimento) específico.
     */
    public function selectAllByEvent(int $movimentoIndex): void
    {
        $movimento = $this->movimentos[$movimentoIndex] ?? null;
        if (!$movimento || empty($movimento['documentos'])) {
            return;
        }

        foreach ($movimento['documentos'] as $doc) {
            $idDoc = $doc['idDocumento'] ?? null;
            if ($idDoc !== null && isset($this->selectedDocuments[$idDoc])) {
                $this->selectedDocuments[$idDoc] = true;
            }
        }
    }

    /**
     * Desmarca todos os documentos de um evento (movimento) específico.
     */
    public function deselectAllByEvent(int $movimentoIndex): void
    {
        $movimento = $this->movimentos[$movimentoIndex] ?? null;
        if (!$movimento || empty($movimento['documentos'])) {
            return;
        }

        foreach ($movimento['documentos'] as $doc) {
            $idDoc = $doc['idDocumento'] ?? null;
            if ($idDoc !== null && isset($this->selectedDocuments[$idDoc])) {
                $this->selectedDocuments[$idDoc] = false;
            }
        }
    }

    /**
     * Alterna seleção de todos os documentos de um evento.
     * Se todos estão selecionados, desmarca todos. Caso contrário, seleciona todos.
     */
    public function toggleEventSelection(int $movimentoIndex): void
    {
        $movimento = $this->movimentos[$movimentoIndex] ?? null;
        if (!$movimento || empty($movimento['documentos'])) {
            return;
        }

        $allSelected = true;
        foreach ($movimento['documentos'] as $doc) {
            $idDoc = $doc['idDocumento'] ?? null;
            if ($idDoc !== null && !($this->selectedDocuments[$idDoc] ?? false)) {
                $allSelected = false;
                break;
            }
        }

        if ($allSelected) {
            $this->deselectAllByEvent($movimentoIndex);
        } else {
            $this->selectAllByEvent($movimentoIndex);
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
                        ? route('filament.analises.resources.historico-processos.view', $ultimaAnalise)
                        : null;
                })
                ->visible(function () {
                    return \App\Models\DocumentAnalysis::where('user_id', auth()->id())
                        ->where('numero_processo', $this->numeroProcesso)
                        ->where('status', 'completed')
                        ->exists();
                }),

            \Filament\Actions\Action::make('analisar_documentos')
                ->label(function () {
                    $count = collect($this->selectedDocuments)->filter()->count();
                    return "Enviar {$count} documento(s) para análise";
                })
                ->icon('heroicon-m-square-3-stack-3d')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirmar Análise de Documentos')
                ->modalDescription(function () {
                    $count = collect($this->selectedDocuments)->filter()->count();
                    return "{$count} documento(s) selecionado(s) serão enviados para análise pela IA. Esta operação pode levar alguns minutos.";
                })
                ->action(function () {
                    $this->enviarParaAnalise();
                })
                ->visible(fn () => !empty($this->documentos))
                ->disabled(function () {
                    $noneSelected = collect($this->selectedDocuments)->filter()->isEmpty();
                    $analysisInProgress = \App\Models\DocumentAnalysis::where('user_id', auth()->id())
                        ->where('numero_processo', $this->numeroProcesso)
                        ->where('status', 'processing')
                        ->exists();
                    return $noneSelected || $analysisInProgress;
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

            // Busca os prompts padrão do sistema por finalidade
            // Prompt para análise individual de documentos (fase MAP) - opcional
            $promptAnaliseDocumentos = \App\Models\AiPrompt::getDefaultForSystemAndType(
                1, // system_id 1 para análise de processos
                \App\Models\AiPrompt::TYPE_DOCUMENT_ANALYSIS
            );

            // Prompt para parecer final (fase REDUCE) - obrigatório
            $promptParecerFinal = \App\Models\AiPrompt::getDefaultForSystemAndType(
                1,
                \App\Models\AiPrompt::TYPE_FINAL_OPINION
            );

            // Fallback: busca prompt antigo sem tipo específico (compatibilidade)
            if (!$promptParecerFinal) {
                $promptParecerFinal = \App\Models\AiPrompt::where('system_id', 1)
                    ->whereNull('prompt_type')
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->first();
            }

            // Usa o prompt de parecer final como referência para configurações de IA
            $promptPadrao = $promptParecerFinal;

            if (!$promptPadrao) {
                \Filament\Notifications\Notification::make()
                    ->title('⚠️ Prompt Não Configurado')
                    ->body('O sistema não possui um prompt padrão configurado para Parecer Final. Configure pelo menos um prompt com finalidade "Parecer Final (REDUCE)" e marque como padrão.')
                    ->danger()
                    ->persistent()
                    ->send();

                Log::warning('Tentativa de análise sem prompt de parecer final configurado', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso
                ]);

                return;
            }

            // Filtra documentos selecionados pelo usuário via controles de seleção da interface
            $selectedIds = collect($this->selectedDocuments)
                ->filter(fn ($selected) => $selected)
                ->keys()
                ->all();

            Log::info('Documentos selecionados pelo usuário', [
                'total_disponíveis' => count($this->documentos),
                'total_selecionados' => count($selectedIds),
                'ids_selecionados' => $selectedIds,
            ]);

            $documentosParaAnalise = collect($this->documentos)
                ->filter(fn ($doc) => in_array($doc['idDocumento'] ?? null, $selectedIds))
                ->sortBy(fn ($doc) => (int) ($doc['sequencia_analise'] ?? 999999))
                ->values()
                ->toArray();

            // Log da ordem final de análise
            if (!empty($documentosParaAnalise)) {
                Log::info('📋 ORDEM FINAL DE ANÁLISE DOS DOCUMENTOS', [
                    'total_documentos' => count($documentosParaAnalise),
                    'ordem_analise' => collect($documentosParaAnalise)->map(fn ($doc) => [
                        'sequencia_global' => $doc['sequencia_analise'] ?? 'N/A',
                        'evento_id' => $doc['idMovimento'] ?? 'N/A',
                        'documento_id' => $doc['idDocumento'] ?? 'N/A',
                        'descricao' => $doc['descricao'] ?? 'Sem descrição',
                    ])->toArray()
                ]);
            }

            if (empty($documentosParaAnalise)) {
                \Filament\Notifications\Notification::make()
                    ->title('📋 Nenhum Documento Selecionado')
                    ->body('Selecione pelo menos um documento para enviar para análise. Use os controles de marcação ao lado de cada documento na lista de eventos.')
                    ->warning()
                    ->persistent()
                    ->send();

                Log::warning('Nenhum documento selecionado para análise', [
                    'user_id' => auth()->user()->id,
                    'numero_processo' => $this->numeroProcesso,
                    'total_documentos' => count($this->documentos),
                ]);

                return;
            }

            // Obtém modelos de IA separados para cada fase
            // REDUCE: modelo do prompt de parecer final
            $reduceModel = $promptPadrao->aiModel;
            $reduceModelId = $reduceModel?->model_id;
            $aiProvider = $reduceModel?->provider ?? $promptPadrao->ai_provider ?? 'openrouter';

            // MAP: modelo do prompt de análise de documentos (fallback para o modelo REDUCE)
            $mapModel = $promptAnaliseDocumentos?->aiModel;
            $mapModelId = $mapModel?->model_id ?? $reduceModelId;

            // Cria a análise imediatamente para permitir redirecionamento direto para a página de acompanhamento.
            $classeProcessual = $this->dadosBasicos['classeProcessualNome']
                ?? $this->dadosBasicos['classeProcessual']
                ?? null;

            $assuntos = collect($this->dadosBasicos['assunto'] ?? [])
                ->map(fn (array $assunto) => $assunto['nomeAssunto']
                    ?? $assunto['descricao']
                    ?? $assunto['codigoAssunto']
                    ?? $assunto['codigoNacional']
                    ?? null)
                ->filter()
                ->implode(', ');

            $documentAnalysis = \App\Models\DocumentAnalysis::create([
                'user_id' => auth()->user()->id,
                'numero_processo' => $this->numeroProcesso,
                'classe_processual' => $classeProcessual,
                'assuntos' => $assuntos !== '' ? $assuntos : null,
                'descricao_documento' => count($documentosParaAnalise) . ' documento(s) do processo',
                'status' => 'processing',
                'current_phase' => \App\Models\DocumentAnalysis::PHASE_DOWNLOAD,
                'progress_message' => 'Aguardando início do processamento pelo worker...',
                'total_documents' => count($documentosParaAnalise),
                'job_parameters' => [
                    'documentos' => $documentosParaAnalise,
                    'contextoDados' => $this->dadosBasicos,
                    'promptTemplate' => $promptPadrao->content,
                    'documentAnalysisPrompt' => $promptAnaliseDocumentos?->content,
                    'aiProvider' => $aiProvider,
                    'ai_provider' => $aiProvider,
                    'deepThinkingEnabled' => $promptPadrao->deep_thinking_enabled ?? true,
                    'deep_thinking_enabled' => $promptPadrao->deep_thinking_enabled ?? true,
                    'aiModelId' => $reduceModelId,
                    'ai_model_id' => $reduceModelId,
                    'mapModelId' => $mapModelId,
                    'map_model_id' => $mapModelId,
                    'reduceStrategy' => 'auto',
                    'reduce_strategy' => 'auto',
                ],
            ]);

            // Dispara o Job com o provider e modelo de IA selecionados
            \App\Jobs\AnalyzeProcessDocuments::dispatch(
                auth()->user()->id,
                $this->numeroProcesso,
                $documentosParaAnalise,
                $this->dadosBasicos,
                $promptPadrao->content,                              // Prompt para parecer final (REDUCE)
                $aiProvider,                                         // Provider de IA (OpenRouter)
                $promptPadrao->deep_thinking_enabled ?? true,        // Modo de pensamento profundo
                \App\Models\JudicialUser::find($this->judicialUserId)->user_login,
                $this->senha,
                $this->judicialUserId,
                $promptPadrao->analysis_strategy ?? 'evolutionary',  // Estratégia de análise
                $reduceModelId,                                      // ID do modelo para REDUCE (parecer final)
                $promptAnaliseDocumentos?->content,                  // Prompt customizado para análise de documentos (MAP)
                $this->chave,                                        // Chave do processo (para processos sigilosos)
                $mapModelId,                                         // ID do modelo para MAP (análise de documentos)
                $documentAnalysis->id                                // ID da análise já criada para redirecionamento imediato
            );

            $totalDocs = count($documentosParaAnalise);
            $modelName = $reduceModel?->name ?? 'IA';
            $providerName = 'OpenRouter';

            \Filament\Notifications\Notification::make()
                ->title('🚀 Análise Iniciada')
                ->body("**Etapa 1/2:** Baixando {$totalDocs} documento(s) do e-Proc...\n\n**Etapa 2/2:** Em seguida, os documentos serão analisados pelo modelo **{$modelName}** ({$providerName}).\n\n⏱️ Este processo pode levar alguns minutos. Você será notificado quando concluir.")
                ->info()
                ->persistent()
                ->send();

            Log::info('Análise de documentos iniciada', [
                'user_id' => auth()->user()->id,
                'numero_processo' => $this->numeroProcesso,
                'total_documentos' => count($documentosParaAnalise)
            ]);

            // Redireciona para a análise recém-criada para acompanhamento em tempo real.
            $this->redirect(
                route('filament.analises.resources.historico-processos.view', $documentAnalysis),
                navigate: true
            );

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
