@php
    $record = $getRecord();

    $snapshot = $record->latestEngineSnapshot;
    $structuredOpinion = $record->structuredOpinion;

    $topRisks = $record->processRisks()
        ->orderByDesc('score')
        ->limit(5)
        ->get();

    $actionPlan = $record->actionPlanItems()
        ->orderBy('ordem')
        ->limit(8)
        ->get();

    $owlex = (array) (($record->analysis_ai_metadata ?? [])['owlex'] ?? []);
    $designerBrief = (array) ($owlex['designer_brief'] ?? []);
@endphp

<div class="space-y-4">
    @if($snapshot)
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Scores do Engine</h4>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-3">
                    <div class="text-xs text-red-600 dark:text-red-400">Risco</div>
                    <div class="text-xl font-bold text-red-700 dark:text-red-300">{{ (int) ($snapshot->risco_processual_score ?? 0) }}</div>
                </div>
                <div class="rounded-lg bg-orange-50 dark:bg-orange-900/20 p-3">
                    <div class="text-xs text-orange-600 dark:text-orange-400">Urgencia</div>
                    <div class="text-xl font-bold text-orange-700 dark:text-orange-300">{{ (int) ($snapshot->urgencia_score ?? 0) }}</div>
                </div>
                <div class="rounded-lg bg-green-50 dark:bg-green-900/20 p-3">
                    <div class="text-xs text-green-600 dark:text-green-400">Oportunidade</div>
                    <div class="text-xl font-bold text-green-700 dark:text-green-300">{{ (int) ($snapshot->oportunidade_score ?? 0) }}</div>
                </div>
                <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3">
                    <div class="text-xs text-blue-600 dark:text-blue-400">Confiabilidade</div>
                    <div class="text-xl font-bold text-blue-700 dark:text-blue-300">{{ (int) ($snapshot->confiabilidade_score ?? 0) }}</div>
                </div>
            </div>
        </div>
    @endif

    @if($structuredOpinion)
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-3">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Parecer Estruturado</h4>

            @if(!empty($structuredOpinion->sumario_executivo))
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Sumario Executivo</p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $structuredOpinion->sumario_executivo }}</p>
                </div>
            @endif

            @if(!empty($structuredOpinion->conclusao_estrategica))
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Conclusao Estrategica</p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $structuredOpinion->conclusao_estrategica }}</p>
                </div>
            @endif
        </div>
    @endif

    @if($topRisks->isNotEmpty())
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Riscos Priorizados</h4>
            <ul class="space-y-2">
                @foreach($topRisks as $risk)
                    <li class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium">[{{ strtoupper((string) ($risk->nivel ?? 'medio')) }}]</span>
                        {{ $risk->descricao }}
                        @if(!is_null($risk->score))
                            <span class="text-gray-500 dark:text-gray-400">(score {{ (int) $risk->score }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($actionPlan->isNotEmpty())
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Plano de Acao</h4>
            <ol class="list-decimal pl-5 space-y-2">
                @foreach($actionPlan as $item)
                    <li class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium">{{ $item->ato_recomendado }}</span>
                        @if(!empty($item->objetivo))
                            <div class="text-xs text-gray-500 dark:text-gray-400">Objetivo: {{ $item->objetivo }}</div>
                        @endif
                        @if(!empty($item->prazo))
                            <div class="text-xs text-gray-500 dark:text-gray-400">Prazo: {{ $item->prazo }}</div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if(!empty($designerBrief))
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-2">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Designer Brief</h4>

            @if(!empty($designerBrief['headline']))
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $designerBrief['headline'] }}</p>
            @endif

            @if(!empty($owlex['generated_at']))
                <p class="text-xs text-gray-500 dark:text-gray-400">Gerado em: {{ $owlex['generated_at'] }}</p>
            @endif
        </div>
    @endif
</div>
