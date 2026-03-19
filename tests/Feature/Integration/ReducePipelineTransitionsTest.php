<?php

use App\Jobs\CheckReduceLevelCompletionJob;
use App\Jobs\ReduceBatchJob;
use App\Jobs\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;

test('reduce document analysis initializes reduce phase and batches reduce jobs', function () {
    Bus::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-31.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 11,
        'job_parameters' => [
            'promptTemplate' => 'Prompt final para consolidacao',
        ],
    ]);

    foreach (range(0, 10) as $index) {
        DocumentMicroAnalysis::query()->create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'id_documento' => 'DOC-' . $index,
            'descricao' => 'Documento ' . $index,
            'status' => 'completed',
            'reduce_level' => 0,
            'micro_analysis' => 'Micro analise ' . $index,
        ]);
    }

    $job = new ReduceDocumentAnalysisJob(
        documentAnalysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: '',
        aiModelId: null,
        currentReduceLevel: 1,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->current_phase)->toBe(DocumentAnalysis::PHASE_REDUCE);
    expect($analysis->reduce_current_level)->toBe(1);
    expect($analysis->reduce_total_batches)->toBe(2);
    expect($analysis->progress_message)->toContain('Consolidando análises');

    Bus::assertBatched(function ($batch) {
        if ($batch->name !== null && !str_starts_with($batch->name, 'reduce_level_1_analysis_')) {
            return false;
        }

        return count($batch->jobs) === 2
            && $batch->jobs[0] instanceof ReduceBatchJob
            && $batch->jobs[1] instanceof ReduceBatchJob;
    });
});

test('check reduce level completion marks analysis as failed when level has no completed results', function () {
    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-32.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 5,
    ]);

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: 'Prompt final',
        aiModelId: null,
        completedReduceLevel: 1,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('failed');
    expect($analysis->error_message)->toContain('Falha na consolidação do nível 1');
});

test('check reduce level completion dispatches next reduce level when threshold is exceeded', function () {
    Queue::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-33.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 11,
    ]);

    foreach (range(1, 11) as $index) {
        DocumentMicroAnalysis::query()->create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => 'Reduce L1 Batch ' . $index,
            'status' => 'completed',
            'reduce_level' => 1,
            'micro_analysis' => 'Consolidado ' . $index,
        ]);
    }

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: 'Prompt final',
        aiModelId: null,
        completedReduceLevel: 1,
    );

    $job->handle();

    Queue::assertPushed(ReduceDocumentAnalysisJob::class, function (ReduceDocumentAnalysisJob $dispatched) use ($analysis) {
        return $dispatched->documentAnalysisId === $analysis->id
            && $dispatched->currentReduceLevel === 2;
    });
});

test('check reduce level completion exits early when analysis is cancelled', function () {
    Queue::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-34.2026.4.04.0000',
        'status' => 'cancelled',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 11,
    ]);

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: 'Prompt final',
        aiModelId: null,
        completedReduceLevel: 1,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('cancelled');
    Queue::assertNotPushed(ReduceDocumentAnalysisJob::class);
});

test('reduce batch job exits immediately when parent batch is cancelled', function () {
    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-35.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
    ]);

    $fakeBatch = new class
    {
        public int $totalJobs = 5;
        public int $failedJobs = 0;

        public function cancelled(): bool
        {
            return true;
        }

        public function cancel(): void
        {
        }
    };

    $job = Mockery::mock(ReduceBatchJob::class, [
        $analysis->id,
        [1, 2],
        1,
        1,
        'openrouter',
        false,
        null,
    ])->makePartial();

    $job->shouldReceive('batch')->andReturn($fakeBatch);

    $job->handle();

    $reduceResults = DocumentMicroAnalysis::query()
        ->where('document_analysis_id', $analysis->id)
        ->where('reduce_level', 1)
        ->count();

    expect($reduceResults)->toBe(0);
});

test('reduce batch job activates dynamic circuit breaker and cancels batch', function () {
    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-36.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
    ]);

    $fakeBatch = new class
    {
        public int $totalJobs = 8;
        public int $failedJobs = 3;
        public bool $cancelCalled = false;

        public function cancelled(): bool
        {
            return false;
        }

        public function cancel(): void
        {
            $this->cancelCalled = true;
        }
    };

    $job = Mockery::mock(ReduceBatchJob::class, [
        $analysis->id,
        [1, 2],
        1,
        1,
        'openrouter',
        false,
        null,
    ])->makePartial();

    $job->shouldReceive('batch')->andReturn($fakeBatch);

    $job->handle();

    expect($fakeBatch->cancelCalled)->toBeTrue();

    $reduceResults = DocumentMicroAnalysis::query()
        ->where('document_analysis_id', $analysis->id)
        ->where('reduce_level', 1)
        ->count();

    expect($reduceResults)->toBe(0);
});

test('reduce document analysis forwards hard entities to final opinion generation', function () {
    setupOpenRouterTestingConfig();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-37.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 2,
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Documento A',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise A',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte A', 'Parte B'],
            'valores_monetarios' => ['R$ 10.000,00'],
            'pontos_chave' => ['Pedido de tutela'],
        ],
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'descricao' => 'Documento B',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise B',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte B', 'Parte C'],
            'valores_monetarios' => ['R$ 10.000,00', 'R$ 3.500,00'],
            'pontos_chave' => ['Pedido de tutela', 'Risco de dano'],
        ],
    ]);

    fakeOpenRouterSingleResponse('Parecer final consolidado com entidades duras.');

    $job = new ReduceDocumentAnalysisJob(
        documentAnalysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: 'Prompt final',
        aiModelId: 'openrouter/test-model',
        currentReduceLevel: 1,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('completed');
    expect($analysis->current_phase)->toBe(DocumentAnalysis::PHASE_COMPLETED);
    expect($analysis->ai_analysis)->toContain('entidades duras');

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $content = $payload['messages'][1]['content'] ?? '';

        return str_contains($content, 'ENTIDADES')
            && substr_count($content, '- Parte A') === 1
            && substr_count($content, '- Parte B') === 1
            && substr_count($content, '- Parte C') === 1
            && substr_count($content, '- R$ 10.000,00') === 1
            && substr_count($content, '- R$ 3.500,00') === 1
            && substr_count($content, '- Pedido de tutela') === 1
            && substr_count($content, '- Risco de dano') === 1;
    });
});

test('reduce batch job persists aggregated entities in reduce micro analysis', function () {
    setupOpenRouterTestingConfig();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-39.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 2,
    ]);

    $micro1 = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Documento A',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise A',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte A', 'Parte B'],
            'valores_monetarios' => ['R$ 1.000,00'],
            'pontos_chave' => ['Ponto 1'],
        ],
    ]);

    $micro2 = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'descricao' => 'Documento B',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise B',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte B', 'Parte C'],
            'valores_monetarios' => ['R$ 1.000,00', 'R$ 2.000,00'],
            'pontos_chave' => ['Ponto 1', 'Ponto 2'],
        ],
    ]);

    fakeOpenRouterSingleResponse('Consolidacao de batch concluida.');

    $job = new ReduceBatchJob(
        documentAnalysisId: $analysis->id,
        microAnalysisIds: [$micro1->id, $micro2->id],
        batchIndex: 1,
        reduceLevel: 1,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        aiModelId: 'openrouter/test-model',
    );

    $job->handle();

    $reduceMicro = DocumentMicroAnalysis::query()
        ->where('document_analysis_id', $analysis->id)
        ->where('reduce_level', 1)
        ->where('document_index', 1)
        ->first();

    expect($reduceMicro)->not->toBeNull();
    expect($reduceMicro->status)->toBe('completed');
    expect($reduceMicro->aggregated_entities)->toBe([
        'partes_mencionadas' => ['Parte A', 'Parte B', 'Parte C'],
        'valores_monetarios' => ['R$ 1.000,00', 'R$ 2.000,00'],
        'pontos_chave' => ['Ponto 1', 'Ponto 2'],
    ]);
});

test('reduce batch job is idempotent on retry when reduce result already completed', function () {
    Http::fake();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-42.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 2,
    ]);

    $micro1 = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Documento A',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise A',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte A'],
            'valores_monetarios' => ['R$ 500,00'],
            'pontos_chave' => ['Ponto A'],
        ],
    ]);

    $micro2 = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'descricao' => 'Documento B',
        'status' => 'completed',
        'reduce_level' => 0,
        'micro_analysis' => 'Analise B',
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte B'],
            'valores_monetarios' => ['R$ 800,00'],
            'pontos_chave' => ['Ponto B'],
        ],
    ]);

    $existingReduce = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Consolidação nível 1 - Batch 1',
        'status' => 'completed',
        'reduce_level' => 1,
        'micro_analysis' => 'Consolidacao existente',
        'parent_ids' => [$micro1->id, $micro2->id],
        'aggregated_entities' => [
            'partes_mencionadas' => ['Parte A', 'Parte B'],
            'valores_monetarios' => ['R$ 500,00', 'R$ 800,00'],
            'pontos_chave' => ['Ponto A', 'Ponto B'],
        ],
    ]);

    $job = new ReduceBatchJob(
        documentAnalysisId: $analysis->id,
        microAnalysisIds: [$micro1->id, $micro2->id],
        batchIndex: 1,
        reduceLevel: 1,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        aiModelId: 'openrouter/test-model',
    );

    $job->handle();

    $allReduce = DocumentMicroAnalysis::query()
        ->where('document_analysis_id', $analysis->id)
        ->where('reduce_level', 1)
        ->get();

    expect($allReduce)->toHaveCount(1);
    expect($allReduce->first()->id)->toBe($existingReduce->id);
    expect($allReduce->first()->micro_analysis)->toBe('Consolidacao existente');
    Http::assertNothingSent();
});

test('check reduce level completion is non-reentrant when analysis is already completed', function () {
    setupOpenRouterTestingConfig();
    Http::fake();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-45.2026.4.04.0000',
        'status' => 'completed',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'total_documents' => 2,
        'ai_analysis' => 'Parecer final já consolidado',
    ]);

    foreach (range(1, 2) as $index) {
        DocumentMicroAnalysis::query()->create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => 'Reduce L1 Batch ' . $index,
            'status' => 'completed',
            'reduce_level' => 1,
            'micro_analysis' => 'Consolidado ' . $index,
        ]);
    }

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: 'Prompt final',
        aiModelId: 'openrouter/test-model',
        completedReduceLevel: 1,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('completed');
    expect($analysis->current_phase)->toBe(DocumentAnalysis::PHASE_COMPLETED);
    expect($analysis->ai_analysis)->toBe('Parecer final já consolidado');
    Http::assertNothingSent();
});
