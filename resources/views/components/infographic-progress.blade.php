@php
    $record = $getRecord();
@endphp

<div class="space-y-4">
    {{-- Header --}}
    <div class="flex items-center gap-3">
        <x-filament::loading-indicator class="h-5 w-5 text-success-600" />
        <span class="text-sm font-medium text-success-700 dark:text-success-300">
            Gerando Infográfico
        </span>
    </div>

    {{-- Barra de progresso --}}
    <div class="space-y-2">
        <div class="flex items-center justify-between text-xs text-success-600 dark:text-success-400">
            <span>{{ $record->infographic_progress_message ?? 'Processando...' }}</span>
            <span class="font-semibold">{{ $record->infographic_progress_percent ?? 0 }}%</span>
        </div>
        <div class="w-full bg-success-200 dark:bg-success-900 rounded-full h-2.5 overflow-hidden">
            <div
                class="bg-success-600 dark:bg-success-500 h-2.5 rounded-full transition-all duration-500 ease-out"
                style="width: {{ $record->infographic_progress_percent ?? 0 }}%"
            ></div>
        </div>
    </div>

    {{-- Fase atual --}}
    @if($record->infographic_current_phase)
        <div class="flex items-center gap-4 text-xs text-success-600 dark:text-success-400">
            <div class="flex items-center gap-2">
                @if($record->infographic_current_phase === 'storyboard')
                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-success-100 dark:bg-success-900 font-medium">
                        <span class="w-2 h-2 rounded-full bg-success-500 animate-pulse"></span>
                        Fase 1: Estrutura JSON
                    </span>
                    <span class="text-gray-400">→</span>
                    <span class="text-gray-400 dark:text-gray-600">Fase 2: Visualização HTML</span>
                @else
                    <span class="text-success-500">✓ Fase 1: Estrutura JSON</span>
                    <span class="text-success-500">→</span>
                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-success-100 dark:bg-success-900 font-medium">
                        <span class="w-2 h-2 rounded-full bg-success-500 animate-pulse"></span>
                        Fase 2: Visualização HTML
                    </span>
                @endif
            </div>
        </div>
    @endif
</div>
