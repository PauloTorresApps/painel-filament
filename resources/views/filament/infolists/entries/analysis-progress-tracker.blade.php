@php
    $record = $getRecord();
    $status = $record->status;
    $phase = $record->current_phase;
    $progressMessage = $record->progress_message;

    // Obtem estatísticas das micro-análises
    $stats = $record->getMicroAnalysisStats();
    $totalDocs = $record->total_documents ?? 0;
    $mapCompleted = min($stats['map_completed'] ?? 0, $totalDocs);
    $processing = $stats['processing'] ?? 0;
    $pending = $stats['pending'] ?? 0;
    $failed = $stats['failed'] ?? 0;

    // Calcula progresso geral (já vem capado em 100 pelo model)
    $overallProgress = $record->getOverallProgressPercentage();

    $isWaitingWorkerStart = $status === 'processing'
        && empty($progressMessage)
        && empty($phase)
        && ($stats['map_completed'] ?? 0) === 0
        && ($stats['processing'] ?? 0) === 0;

    $effectiveProgressMessage = $isWaitingWorkerStart
        ? 'Aguardando início do processamento pelo worker...'
        : ($progressMessage ?? 'Análise em andamento');

    // Busca micro-análises do nível MAP ordenadas por índice
    $microAnalyses = $record->microAnalyses()
        ->mapLevel()
        ->orderBy('document_index')
        ->get();

    // Define cores e ícones por status
    $statusConfig = [
        'pending' => [
            'color' => 'gray',
            'bgColor' => 'bg-gray-100 dark:bg-gray-800',
            'textColor' => 'text-gray-600 dark:text-gray-400',
            'icon' => 'heroicon-o-clock',
            'label' => 'Aguardando',
        ],
        'processing' => [
            'color' => 'blue',
            'bgColor' => 'bg-blue-100 dark:bg-blue-900/30',
            'textColor' => 'text-blue-600 dark:text-blue-400',
            'icon' => 'heroicon-o-arrow-path',
            'label' => 'Analisando',
            'animate' => true,
        ],
        'completed' => [
            'color' => 'green',
            'bgColor' => 'bg-green-100 dark:bg-green-900/30',
            'textColor' => 'text-green-600 dark:text-green-400',
            'icon' => 'heroicon-o-check-circle',
            'label' => 'Concluído',
        ],
        'failed' => [
            'color' => 'red',
            'bgColor' => 'bg-red-100 dark:bg-red-900/30',
            'textColor' => 'text-red-600 dark:text-red-400',
            'icon' => 'heroicon-o-x-circle',
            'label' => 'Falhou',
        ],
    ];

    // Fases do processo
    $phases = [
        'download' => ['label' => 'Download', 'icon' => 'heroicon-o-arrow-down-tray'],
        'map' => ['label' => 'Análise Individual', 'icon' => 'heroicon-o-square-3-stack-3d'],
        'reduce' => ['label' => 'Consolidação', 'icon' => 'heroicon-o-squares-plus'],
        'completed' => ['label' => 'Concluído', 'icon' => 'heroicon-o-check-badge'],
    ];

    $currentPhaseIndex = array_search($phase, array_keys($phases));
@endphp

<div class="space-y-6" wire:poll.8s>
    {{-- Status Geral --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                @if($status === 'processing')
                    <div class="relative">
                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <x-heroicon-o-arrow-path class="w-6 h-6 text-blue-600 dark:text-blue-400 animate-spin" />
                        </div>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Processando...</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $effectiveProgressMessage }}</p>
                    </div>
                @elseif($status === 'failed')
                    <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                        <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-red-600 dark:text-red-400">Análise com Erro</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $progressMessage ?? 'Ocorreu um erro durante o processamento' }}</p>
                    </div>
                @elseif($status === 'pending')
                    <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <x-heroicon-o-clock class="w-6 h-6 text-gray-600 dark:text-gray-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Aguardando</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Análise na fila de processamento</p>
                    </div>
                @elseif($status === 'cancelled')
                    <div class="w-10 h-10 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                        <x-heroicon-o-no-symbol class="w-6 h-6 text-orange-600 dark:text-orange-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-orange-600 dark:text-orange-400">Cancelada</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Análise foi cancelada</p>
                    </div>
                @endif
            </div>

            <div class="text-right">
                <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ round($overallProgress) }}%</span>
                <p class="text-sm text-gray-500 dark:text-gray-400">Progresso Geral</p>
            </div>
        </div>

        {{-- Barra de Progresso --}}
        @php $progressInt = (int) round($overallProgress); @endphp
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
            <div
                wire:key="progress-bar-{{ $progressInt }}"
                class="h-full rounded-full"
                style="width: {{ $progressInt }}%;{{ $progressInt > 0 ? ' min-width: 0.75rem;' : '' }} background-color: {{ $status === 'failed' ? '#ef4444' : '#3b82f6' }};"
            ></div>
        </div>
    </div>

    {{-- Fases do Processo --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-sm">
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Etapas do Processamento</h4>

        @php
            $phaseKeys = array_keys($phases);
            $totalSegments = count($phases) - 1;
            $completedSegments = $status === 'completed' ? $totalSegments : max(0, (int) $currentPhaseIndex);
            $lineProgressPct = $totalSegments > 0 ? (int) round(($completedSegments / $totalSegments) * 100) : 0;
        @endphp

        <div class="flex items-center justify-between relative">
            {{-- Linha de conexão (base cinza) --}}
            <div class="absolute top-5 left-0 right-0 h-0.5 bg-gray-200 dark:bg-gray-700 mx-12"></div>

            {{-- Linha de conexão (progresso verde) --}}
            @if($lineProgressPct > 0)
                <div wire:key="phase-line-{{ $lineProgressPct }}" class="absolute top-5 left-0 right-0 h-0.5 mx-12" style="background: linear-gradient(to right, #22c55e {{ $lineProgressPct }}%, transparent {{ $lineProgressPct }}%);"></div>
            @endif

            @foreach($phases as $phaseKey => $phaseConfig)
                @php
                    $phaseIndex = array_search($phaseKey, $phaseKeys);
                    $isCompleted = $currentPhaseIndex !== false && $currentPhaseIndex > $phaseIndex;
                    if ($status === 'completed') $isCompleted = true;
                    $isCurrent = $phase === $phaseKey && in_array($status, ['processing', 'failed']);
                    $isPending = !$isCompleted && !$isCurrent;
                @endphp

                @php
                    if ($status === 'failed' && $isCurrent) {
                        $circleStyle = 'background-color: #ef4444; color: white; box-shadow: 0 0 0 4px #fecaca;';
                        $labelStyle = 'color: #ef4444;';
                    } elseif ($isCompleted) {
                        $circleStyle = 'background-color: #22c55e; color: white;';
                        $labelStyle = 'color: #16a34a;';
                    } elseif ($isCurrent) {
                        $circleStyle = 'background-color: #3b82f6; color: white; box-shadow: 0 0 0 4px #bfdbfe;';
                        $labelStyle = 'color: #2563eb;';
                    } else {
                        $circleStyle = '';
                        $labelStyle = '';
                    }
                @endphp

                <div class="flex flex-col items-center relative z-10" wire:key="phase-{{ $phaseKey }}-{{ $status }}-{{ $phase }}">
                    <div
                        class="w-10 h-10 rounded-full flex items-center justify-center mb-2 {{ $isPending ? 'bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500' : '' }}"
                        style="{{ $circleStyle }}"
                    >
                        <x-dynamic-component :component="$phaseConfig['icon']" class="w-5 h-5 {{ $isCurrent && $status === 'processing' ? 'animate-phase-pulse' : '' }}" />
                    </div>
                    <span
                        class="text-xs font-medium text-center {{ $isPending ? 'text-gray-400 dark:text-gray-500' : '' }}"
                        style="{{ $labelStyle }}"
                    >
                        {{ $phaseConfig['label'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Estatísticas Rápidas --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-center">
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalDocs }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total de Documentos</div>
        </div>
        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3 text-center">
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $mapCompleted }}</div>
            <div class="text-xs text-green-600 dark:text-green-400">Concluídos</div>
        </div>
        <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-3 text-center">
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $processing }}</div>
            <div class="text-xs text-blue-600 dark:text-blue-400">Em Análise</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3 text-center">
            <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ $pending }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Aguardando</div>
        </div>
    </div>

    {{-- Lista de Documentos --}}
    @if($microAnalyses->isNotEmpty())
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Documentos do Processo</h4>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
                @foreach($microAnalyses as $micro)
                    @php
                        $microStatus = $micro->status;
                        $config = $statusConfig[$microStatus] ?? $statusConfig['pending'];
                        $processingTime = $micro->processing_time_ms
                            ? round($micro->processing_time_ms / 1000, 1) . 's'
                            : null;
                    @endphp

                    <div class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        {{-- Índice --}}
                        <div class="flex-shrink-0 w-8 h-8 rounded-full {{ $config['bgColor'] }} flex items-center justify-center">
                            <span class="text-xs font-semibold {{ $config['textColor'] }}">
                                {{ str_pad($micro->document_index, 2, '0', STR_PAD_LEFT) }}
                            </span>
                        </div>

                        {{-- Ícone de Status --}}
                        <div class="flex-shrink-0">
                            @if(isset($config['animate']) && $config['animate'])
                                <x-dynamic-component
                                    :component="$config['icon']"
                                    class="w-5 h-5 {{ $config['textColor'] }} animate-spin"
                                />
                            @else
                                <x-dynamic-component
                                    :component="$config['icon']"
                                    class="w-5 h-5 {{ $config['textColor'] }}"
                                />
                            @endif
                        </div>

                        {{-- Nome do Documento --}}
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate" title="{{ $micro->descricao }}">
                                {{ $micro->descricao }}
                            </p>
                            @if($micro->mimetype)
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $micro->mimetype }}
                                </p>
                            @endif
                        </div>

                        {{-- Status Badge --}}
                        <div class="flex-shrink-0 flex items-center gap-2">
                            @if($processingTime && $microStatus === 'completed')
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $processingTime }}
                                </span>
                            @endif

                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $config['bgColor'] }} {{ $config['textColor'] }}">
                                {{ $config['label'] }}
                            </span>
                        </div>

                        {{-- Erro (se houver) --}}
                        @if($microStatus === 'failed' && $micro->error_message)
                            <button
                                type="button"
                                x-data
                                x-tooltip.raw="{{ Str::limit($micro->error_message, 100) }}"
                                class="flex-shrink-0 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                            >
                                <x-heroicon-o-information-circle class="w-5 h-5" />
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($microAnalyses->count() > 10)
                <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-center text-gray-500 dark:text-gray-400">
                        Mostrando {{ $microAnalyses->count() }} documentos
                    </p>
                </div>
            @endif
        </div>
    @endif

    {{-- Informações sobre Reduce (se aplicável) --}}
    @if($phase === 'reduce')
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
            <div class="flex items-start gap-3">
                <x-heroicon-o-squares-plus class="w-6 h-6 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                <div>
                    <h4 class="text-sm font-semibold text-amber-800 dark:text-amber-200">Fase de Consolidação</h4>
                    <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                        As análises individuais estão sendo consolidadas em uma visão unificada do processo.
                        @if($record->reduce_total_levels > 1)
                            <br>
                            <span class="font-medium">
                                Nível {{ $record->reduce_current_level ?? 1 }} de {{ $record->reduce_total_levels }}
                            </span>
                            @if($record->reduce_total_batches > 0)
                                - Lote {{ $record->reduce_processed_batches ?? 0 }}/{{ $record->reduce_total_batches }}
                            @endif
                        @endif
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Última atualização --}}
    <div class="text-center text-xs text-gray-400 dark:text-gray-500">
        Última atualização: {{ $record->updated_at->format('d/m/Y H:i:s') }}
        <span class="mx-1">|</span>
        Atualização automática a cada 5 segundos
    </div>
</div>

<style>
    @keyframes phase-pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.35); opacity: 0.7; }
    }
    .animate-phase-pulse {
        animation: phase-pulse 1s ease-in-out infinite;
    }
</style>
