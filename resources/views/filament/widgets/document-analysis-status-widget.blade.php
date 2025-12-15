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
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" wire:poll.5s>
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
            $analyses = $this->getAnalyses();
        @endphp

        @if($analyses->count() > 0)
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

                @if($analyses->count() >= 10)
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
