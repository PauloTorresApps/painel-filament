<x-filament-panels::page>
    {{-- CSS do FilePond --}}
    <link href="https://unpkg.com/filepond@^4/dist/filepond.css" rel="stylesheet" />

    <div class="space-y-6">
        {{-- Card de Upload --}}
        <x-filament::section>
            <x-slot name="heading">
                Upload de Contrato
            </x-slot>
            <x-slot name="description">
                Selecione um arquivo PDF para análise. Tamanho máximo: 100MB.
            </x-slot>

            <div class="space-y-4">
                {{-- FilePond Container --}}
                <div wire:ignore>
                    <input type="file" id="contract-upload" name="contract" accept="application/pdf" />
                </div>

                {{-- Informações do arquivo selecionado --}}
                @if($this->uploadedFileName)
                    <div class="flex items-center gap-2 p-3 bg-success-50 dark:bg-success-950 rounded-lg border border-success-200 dark:border-success-800">
                        <x-heroicon-o-document-check class="w-5 h-5 text-success-600 dark:text-success-400" />
                        <span class="text-sm text-success-700 dark:text-success-300">
                            Arquivo pronto: <strong>{{ $this->uploadedFileName }}</strong>
                        </span>
                    </div>
                @endif

                {{-- Status de análise em andamento --}}
                @if($this->isAnalyzing)
                    <div class="flex items-center gap-2 p-3 bg-info-50 dark:bg-info-950 rounded-lg border border-info-200 dark:border-info-800">
                        <x-filament::loading-indicator class="h-5 w-5 text-info-600" />
                        <span class="text-sm text-info-700 dark:text-info-300">
                            Análise em andamento...
                        </span>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Card de Status/Última Análise --}}
        @if($this->latestAnalysis)
            <x-filament::section>
                <x-slot name="heading">
                    Última Análise
                </x-slot>

                <div class="space-y-4" wire:poll.5s="refreshAnalysisStatus">
                    {{-- Header com status --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-document-text class="w-6 h-6 text-gray-400" />
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">
                                    {{ $this->latestAnalysis->file_name }}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $this->latestAnalysis->created_at->format('d/m/Y H:i') }}
                                    &bull;
                                    {{ $this->latestAnalysis->formatted_file_size }}
                                </p>
                            </div>
                        </div>

                        <x-filament::badge :color="$this->latestAnalysis->status_badge_color">
                            {{ $this->latestAnalysis->status_label }}
                        </x-filament::badge>
                    </div>

                    {{-- Progresso se estiver processando --}}
                    @if($this->latestAnalysis->isProcessing())
                        <div class="flex items-center gap-3 p-4 bg-info-50 dark:bg-info-950 rounded-lg">
                            <x-filament::loading-indicator class="h-5 w-5 text-info-600" />
                            <span class="text-sm text-info-700 dark:text-info-300">
                                A IA está analisando o contrato. Isso pode levar alguns minutos...
                            </span>
                        </div>
                    @endif

                    {{-- Erro se falhou --}}
                    @if($this->latestAnalysis->isFailed())
                        <div class="p-4 bg-danger-50 dark:bg-danger-950 rounded-lg border border-danger-200 dark:border-danger-800">
                            <p class="text-sm text-danger-700 dark:text-danger-300">
                                <strong>Erro:</strong> {{ $this->latestAnalysis->error_message }}
                            </p>
                        </div>
                    @endif

                    {{-- Resultado se concluído --}}
                    @if($this->latestAnalysis->isCompleted() && $this->latestAnalysis->analysis_result)
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                    Resultado da Análise
                                </h4>
                                @if($this->latestAnalysis->processing_time_ms)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        Tempo: {{ number_format($this->latestAnalysis->processing_time_ms / 1000, 1) }}s
                                    </span>
                                @endif
                            </div>

                            <div class="prose prose-sm dark:prose-invert max-w-none bg-gray-50 dark:bg-gray-900 rounded-lg p-4 max-h-[400px] overflow-y-auto">
                                {!! \Illuminate\Support\Str::markdown($this->latestAnalysis->analysis_result) !!}
                            </div>

                            {{-- Botão para gerar parecer jurídico --}}
                            @if($this->latestAnalysis->canGenerateLegalOpinion() && !$this->latestAnalysis->isLegalOpinionCompleted())
                                <div class="mt-4 flex justify-end">
                                    <x-filament::button
                                        wire:click="generateLegalOpinion"
                                        :disabled="$this->isGeneratingLegalOpinion"
                                        icon="heroicon-o-scale"
                                        color="primary"
                                    >
                                        @if($this->isGeneratingLegalOpinion || $this->latestAnalysis->isLegalOpinionProcessing())
                                            <x-filament::loading-indicator class="h-4 w-4 mr-2" />
                                            Gerando Parecer...
                                        @else
                                            Gerar Parecer Jurídico
                                        @endif
                                    </x-filament::button>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Parecer Jurídico em processamento --}}
                    @if($this->latestAnalysis->isLegalOpinionProcessing())
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex items-center gap-3 p-4 bg-info-50 dark:bg-info-950 rounded-lg">
                                <x-filament::loading-indicator class="h-5 w-5 text-info-600" />
                                <span class="text-sm text-info-700 dark:text-info-300">
                                    O parecer jurídico está sendo gerado. Isso pode levar alguns minutos...
                                </span>
                            </div>
                        </div>
                    @endif

                    {{-- Erro no parecer jurídico --}}
                    @if($this->latestAnalysis->isLegalOpinionFailed())
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="p-4 bg-danger-50 dark:bg-danger-950 rounded-lg border border-danger-200 dark:border-danger-800">
                                <p class="text-sm text-danger-700 dark:text-danger-300">
                                    <strong>Erro no Parecer Jurídico:</strong> {{ $this->latestAnalysis->legal_opinion_error }}
                                </p>
                            </div>
                            {{-- Botão para tentar novamente --}}
                            <div class="mt-3 flex justify-end">
                                <x-filament::button
                                    wire:click="generateLegalOpinion"
                                    icon="heroicon-o-arrow-path"
                                    color="warning"
                                    size="sm"
                                >
                                    Tentar Novamente
                                </x-filament::button>
                            </div>
                        </div>
                    @endif

                    {{-- Parecer Jurídico concluído --}}
                    @if($this->latestAnalysis->isLegalOpinionCompleted() && $this->latestAnalysis->legal_opinion_result)
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white flex items-center gap-2">
                                    <x-heroicon-o-scale class="w-5 h-5 text-primary-600" />
                                    Parecer Jurídico
                                </h4>
                                <div class="flex items-center gap-3">
                                    @if($this->latestAnalysis->legal_opinion_processing_time_ms)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            Tempo: {{ number_format($this->latestAnalysis->legal_opinion_processing_time_ms / 1000, 1) }}s
                                        </span>
                                    @endif
                                    <x-filament::badge color="success">
                                        {{ $this->latestAnalysis->legal_opinion_status_label }}
                                    </x-filament::badge>
                                </div>
                            </div>

                            <div class="prose prose-sm dark:prose-invert max-w-none bg-gray-50 dark:bg-gray-900 rounded-lg p-4 max-h-[400px] overflow-y-auto">
                                {!! \Illuminate\Support\Str::markdown($this->latestAnalysis->legal_opinion_result) !!}
                            </div>

                            {{-- Botões de ação do parecer --}}
                            <div class="mt-4 flex justify-end gap-3">
                                <x-filament::button
                                    tag="a"
                                    href="{{ route('contracts.legal-opinion.view', $this->latestAnalysis->id) }}"
                                    target="_blank"
                                    icon="heroicon-o-eye"
                                    color="gray"
                                    size="sm"
                                >
                                    Visualizar PDF
                                </x-filament::button>

                                <x-filament::button
                                    tag="a"
                                    href="{{ route('contracts.legal-opinion.download', $this->latestAnalysis->id) }}"
                                    icon="heroicon-o-arrow-down-tray"
                                    color="primary"
                                    size="sm"
                                >
                                    Download PDF
                                </x-filament::button>
                            </div>
                        </div>
                    @endif

                    {{-- Link para histórico --}}
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <a href="{{ route('filament.admin.resources.contract-analysis.contract-analyses.index') }}"
                           class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 flex items-center gap-1">
                            <x-heroicon-o-clock class="w-4 h-4" />
                            Ver histórico de análises
                        </a>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>

    {{-- Scripts do FilePond --}}
    <script src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.js"></script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.js"></script>
    <script src="https://unpkg.com/filepond@^4/dist/filepond.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Registra plugins
            FilePond.registerPlugin(
                FilePondPluginFileValidateType,
                FilePondPluginFileValidateSize
            );

            // Cria instância do FilePond
            const pond = FilePond.create(document.querySelector('#contract-upload'), {
                // Configurações básicas
                allowMultiple: false,
                maxFiles: 1,

                // Validação de tipo
                acceptedFileTypes: ['application/pdf'],
                fileValidateTypeLabelExpectedTypes: 'Apenas arquivos PDF são aceitos',

                // Validação de tamanho
                maxFileSize: '100MB',
                labelMaxFileSizeExceeded: 'Arquivo muito grande',
                labelMaxFileSize: 'Tamanho máximo: {filesize}',

                // Chunked upload
                chunkUploads: true,
                chunkSize: 10485760, // 10MB
                chunkForce: false, // Só usa chunks se > chunkSize

                // Labels em português
                labelIdle: 'Arraste e solte seu contrato PDF aqui ou <span class="filepond--label-action">Selecione</span>',
                labelFileProcessing: 'Enviando...',
                labelFileProcessingComplete: 'Upload concluído',
                labelFileProcessingAborted: 'Upload cancelado',
                labelFileProcessingError: 'Erro no upload',
                labelTapToCancel: 'clique para cancelar',
                labelTapToRetry: 'clique para tentar novamente',
                labelTapToUndo: 'clique para remover',
                labelButtonRemoveItem: 'Remover',
                labelButtonAbortItemLoad: 'Cancelar',
                labelButtonRetryItemLoad: 'Tentar novamente',
                labelButtonAbortItemProcessing: 'Cancelar',
                labelButtonUndoItemProcessing: 'Desfazer',
                labelButtonRetryItemProcessing: 'Tentar novamente',
                labelButtonProcessItem: 'Enviar',

                // Configuração do servidor
                server: {
                    url: '/contracts',
                    process: {
                        url: '/upload',
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        onload: (response) => {
                            const data = JSON.parse(response);
                            if (data.success) {
                                // Dispara evento Livewire com os dados do arquivo
                                @this.dispatch('contract-uploaded', {
                                    filePath: data.file_path,
                                    fileName: data.file_name,
                                    fileSize: data.file_size
                                });
                            }
                            return data.file_id;
                        },
                        onerror: (response) => {
                            console.error('Erro no upload:', response);
                            return response;
                        }
                    },
                    revert: {
                        url: '/upload',
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        }
                    }
                }
            });

            // Evento quando arquivo é removido
            pond.on('removefile', () => {
                @this.dispatch('contract-removed');
            });
        });
    </script>

    <style>
        /* Customização do FilePond para combinar com Filament */
        .filepond--root {
            font-family: inherit;
        }

        .filepond--panel-root {
            background-color: rgb(var(--gray-50));
            border: 2px dashed rgb(var(--gray-300));
            border-radius: 0.5rem;
        }

        .dark .filepond--panel-root {
            background-color: rgb(var(--gray-900));
            border-color: rgb(var(--gray-700));
        }

        .filepond--drop-label {
            color: rgb(var(--gray-600));
        }

        .dark .filepond--drop-label {
            color: rgb(var(--gray-400));
        }

        .filepond--label-action {
            color: rgb(var(--primary-600));
            text-decoration: underline;
        }

        .dark .filepond--label-action {
            color: rgb(var(--primary-400));
        }

        .filepond--item-panel {
            background-color: rgb(var(--gray-100));
        }

        .dark .filepond--item-panel {
            background-color: rgb(var(--gray-800));
        }

        .filepond--file-action-button {
            cursor: pointer;
        }
    </style>
</x-filament-panels::page>
