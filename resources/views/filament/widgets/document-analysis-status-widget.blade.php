<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="text-lg font-semibold">Status das Análises de IA</span>
                </div>

                @if($this->getProcessingCount() > 0 || $this->getPendingCount() > 0)
                    <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span>Atualizando...</span>
                    </div>
                @endif
            </div>
        </x-slot>

        {{-- Resumo de Status --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" @if($this->getProcessingCount() > 0 || $this->getPendingCount() > 0) wire:poll.10s @endif>
            <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-900/20 border border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Pendentes</span>
                    <span class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ $this->getPendingCount() }}</span>
                </div>
            </div>

            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/15 border border-amber-200/50 dark:border-amber-800/30">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Processando</span>
                    <span class="text-lg font-bold text-amber-900 dark:text-amber-100">{{ $this->getProcessingCount() }}</span>
                </div>
            </div>

            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/15 border border-emerald-200/50 dark:border-emerald-800/30">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Concluídas</span>
                    <span class="text-lg font-bold text-emerald-900 dark:text-emerald-100">{{ $this->getCompletedCount() }}</span>
                </div>
            </div>

            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/15 border border-red-200/50 dark:border-red-800/30">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-red-700 dark:text-red-300">Falhas</span>
                    <span class="text-lg font-bold text-red-900 dark:text-red-100">{{ $this->getFailedCount() }}</span>
                </div>
            </div>
        </div>

        {{-- Lista de Análises Recentes --}}
        @php
            $analysesPaginated = $this->getAnalyses();
            $analyses = $analysesPaginated->items();
        @endphp

        @if(count($analyses) > 0)
            <div class="space-y-2">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Análises Recentes</h4>

                @foreach($analyses as $analysis)
                    <div class="p-3 rounded-lg border {{ $analysis->status === 'completed' ? 'border-emerald-200 dark:border-emerald-800/40 bg-emerald-50/30 dark:bg-emerald-900/5' : ($analysis->status === 'failed' ? 'border-red-200 dark:border-red-800/40 bg-red-50/30 dark:bg-red-900/5' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-800') }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                        {{ $analysis->descricao_documento ?? 'Documento #' . $analysis->id }}
                                    </p>

                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $analysis->status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : '' }}
                                        {{ $analysis->status === 'processing' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : '' }}
                                        {{ $analysis->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : '' }}
                                        {{ $analysis->status === 'pending' ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' : '' }}
                                    ">
                                        @if($analysis->status === 'completed')
                                            ✓ Concluído
                                        @elseif($analysis->status === 'processing')
                                            ⟳ Processando
                                        @elseif($analysis->status === 'failed')
                                            ✗ Falhou
                                        @else
                                            ⋯ Pendente
                                        @endif
                                    </span>
                                </div>

                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Processo: {{ $analysis->numero_processo }}
                                    @if($analysis->processing_time_ms)
                                        • Tempo: {{ round($analysis->processing_time_ms / 1000, 2) }}s
                                    @endif
                                    • {{ $analysis->created_at->diffForHumans() }}
                                </p>

                                @if($analysis->status === 'failed' && $analysis->error_message)
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">
                                        Erro: {{ Str::limit($analysis->error_message, 100) }}
                                    </p>
                                @endif

                                {{-- Detalhes de progresso para análises em processamento --}}
                                @if($analysis->status === 'processing' && $analysis->current_phase)
                                    <div class="mt-2 p-2 rounded-md bg-amber-50/50 dark:bg-amber-900/10 border border-amber-200/50 dark:border-amber-800/30">
                                        {{-- Indicador de fases --}}
                                        <div class="flex items-center gap-1 mb-2">
                                            {{-- Download --}}
                                            <div class="flex items-center gap-1 {{ $analysis->current_phase === 'download' ? 'text-amber-600 dark:text-amber-400' : ($analysis->current_phase !== 'download' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400') }}">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                                </svg>
                                                <span class="text-xs font-medium">Download</span>
                                            </div>
                                            <svg class="w-3 h-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>

                                            {{-- MAP --}}
                                            <div class="flex items-center gap-1 {{ $analysis->current_phase === 'map' ? 'text-amber-600 dark:text-amber-400' : (in_array($analysis->current_phase, ['reduce', 'completed']) ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400') }}">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                                </svg>
                                                <span class="text-xs font-medium">Análise Individual</span>
                                            </div>
                                            <svg class="w-3 h-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>

                                            {{-- REDUCE --}}
                                            <div class="flex items-center gap-1 {{ $analysis->current_phase === 'reduce' ? 'text-amber-600 dark:text-amber-400' : ($analysis->current_phase === 'completed' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400') }}">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6z"/>
                                                </svg>
                                                <span class="text-xs font-medium">Consolidação</span>
                                            </div>
                                        </div>

                                        {{-- Barra de progresso --}}
                                        <div class="relative w-full h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden mb-1.5">
                                            <div class="absolute left-0 top-0 h-full bg-gradient-to-r from-amber-400 to-amber-500 dark:from-amber-500 dark:to-amber-400 rounded-full transition-all duration-500"
                                                 style="width: {{ $analysis->getOverallProgressPercentage() }}%"></div>
                                        </div>

                                        {{-- Mensagem de progresso --}}
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs text-amber-700 dark:text-amber-300">
                                                {{ $analysis->progress_message ?? $analysis->getCurrentPhaseLabel() }}
                                            </p>
                                            <span class="text-xs font-medium text-amber-600 dark:text-amber-400">
                                                {{ round($analysis->getOverallProgressPercentage()) }}%
                                            </span>
                                        </div>

                                        {{-- Detalhes adicionais da fase REDUCE --}}
                                        @if($analysis->current_phase === 'reduce' && $analysis->reduce_total_levels > 0)
                                            <p class="text-xs text-amber-600/80 dark:text-amber-400/80 mt-1">
                                                Nível {{ $analysis->reduce_current_level }}/{{ $analysis->reduce_total_levels }}
                                                @if($analysis->reduce_total_batches > 0)
                                                    • Lote {{ $analysis->reduce_processed_batches }}/{{ $analysis->reduce_total_batches }}
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if($analysis->status === 'completed')
                                <a href="{{ route('filament.admin.resources.document-analyses.view', $analysis) }}"
                                   class="flex-shrink-0 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 dark:text-indigo-300 dark:bg-indigo-900/20 dark:hover:bg-indigo-900/30 rounded-lg transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    Ver Análise
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- Controles de Paginação --}}
                @if($analysesPaginated->hasPages())
                    <div class="flex items-center justify-between pt-3 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="previousPage"
                                @disabled($analysesPaginated->onFirstPage())
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg transition
                                    {{ $analysesPaginated->onFirstPage()
                                        ? 'text-gray-400 dark:text-gray-600 bg-gray-100 dark:bg-gray-800 cursor-not-allowed'
                                        : 'text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600' }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                                Anterior
                            </button>

                            <span class="text-xs text-gray-600 dark:text-gray-400">
                                Página {{ $analysesPaginated->currentPage() }} de {{ $analysesPaginated->lastPage() }}
                                <span class="text-gray-400 dark:text-gray-500">•</span>
                                {{ $analysesPaginated->total() }} {{ $analysesPaginated->total() === 1 ? 'análise' : 'análises' }}
                            </span>

                            <button
                                wire:click="nextPage"
                                @disabled(!$analysesPaginated->hasMorePages())
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg transition
                                    {{ !$analysesPaginated->hasMorePages()
                                        ? 'text-gray-400 dark:text-gray-600 bg-gray-100 dark:bg-gray-800 cursor-not-allowed'
                                        : 'text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600' }}">
                                Próxima
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        <a href="{{ route('filament.admin.resources.document-analyses.index') }}"
                           class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium">
                            Ver todas →
                        </a>
                    </div>
                @else
                    <div class="text-center pt-2">
                        <a href="{{ route('filament.admin.resources.document-analyses.index') }}"
                           class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium">
                            Ver todas as análises →
                        </a>
                    </div>
                @endif
            </div>
        @else
            <div class="text-center py-8">
                <svg class="w-12 h-12 mx-auto text-gray-400 dark:text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nenhuma análise em andamento ou recente.
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    Clique em "Enviar todos os documentos para análise" para começar.
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
