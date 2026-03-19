<?php

use App\Jobs\DispatchMapPhaseJob;
use App\Jobs\MapDocumentAnalysisJob;
use App\Jobs\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;

test('dispatch map phase aborts analysis when map batch failure rate exceeds threshold', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-38.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_DOWNLOAD,
        'total_documents' => 4,
    ]);

    foreach (range(1, 4) as $index) {
        DocumentMicroAnalysis::query()->create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'id_documento' => 'DOC-' . $index,
            'descricao' => 'Documento ' . $index,
            'status' => 'pending',
            'reduce_level' => 0,
            'extracted_text' => 'Texto de teste do documento ' . $index,
        ]);
    }

    $thenCallback = null;

    $pendingBatchMock = Mockery::mock();
    $pendingBatchMock->shouldReceive('name')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('onQueue')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('allowFailures')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('then')->once()->andReturnUsing(function (callable $callback) use (&$thenCallback, $pendingBatchMock) {
        $thenCallback = $callback;
        return $pendingBatchMock;
    });
    $pendingBatchMock->shouldReceive('catch')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('progress')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('finally')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$thenCallback) {
        $fakeBatch = new Batch(
            Mockery::mock(QueueFactory::class),
            Mockery::mock(BatchRepository::class),
            'map-batch-test-id',
            'map_batch_test',
            8,
            0,
            3,
            [],
            [],
            CarbonImmutable::now(),
            null,
            null,
        );

        if ($thenCallback) {
            $thenCallback($fakeBatch);
        }

        return $fakeBatch;
    });

    Bus::shouldReceive('batch')
        ->once()
        ->withArgs(function (array $jobs) {
            return count($jobs) === 4;
        })
        ->andReturn($pendingBatchMock);

    $job = new DispatchMapPhaseJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        userId: $user->id,
        reduceStrategy: 'auto',
        mapModelId: null,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('failed');
    expect($analysis->error_message)->toContain('Análise abortada');
    expect($analysis->error_message)->toContain('3 de 8');
});

test('dispatch map phase marks analysis as failed when batch is cancelled in finally callback', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-40.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_DOWNLOAD,
        'total_documents' => 4,
    ]);

    foreach (range(1, 4) as $index) {
        DocumentMicroAnalysis::query()->create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'id_documento' => 'DOC-' . $index,
            'descricao' => 'Documento ' . $index,
            'status' => 'pending',
            'reduce_level' => 0,
            'extracted_text' => 'Texto de teste do documento ' . $index,
        ]);
    }

    $finallyCallback = null;

    $pendingBatchMock = Mockery::mock();
    $pendingBatchMock->shouldReceive('name')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('onQueue')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('allowFailures')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('then')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('catch')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('progress')->once()->andReturnSelf();
    $pendingBatchMock->shouldReceive('finally')->once()->andReturnUsing(function (callable $callback) use (&$finallyCallback, $pendingBatchMock) {
        $finallyCallback = $callback;
        return $pendingBatchMock;
    });
    $pendingBatchMock->shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$finallyCallback) {
        $cancelledBatch = new Batch(
            Mockery::mock(QueueFactory::class),
            Mockery::mock(BatchRepository::class),
            'map-batch-cancelled-id',
            'map_batch_cancelled_test',
            8,
            0,
            5,
            [],
            [],
            CarbonImmutable::now(),
            Carbon::now()->toImmutable(),
            null,
        );

        if ($finallyCallback) {
            $finallyCallback($cancelledBatch);
        }

        return $cancelledBatch;
    });

    Bus::shouldReceive('batch')
        ->once()
        ->withArgs(function (array $jobs) {
            return count($jobs) === 4;
        })
        ->andReturn($pendingBatchMock);

    $job = new DispatchMapPhaseJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        userId: $user->id,
        reduceStrategy: 'auto',
        mapModelId: null,
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('failed');
    expect($analysis->error_message)->toContain('Limite dinâmico de falhas excedido');
});

test('map document analysis job is idempotent and skips already completed micro analysis', function () {
    Http::fake();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-41.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 1,
    ]);

    $micro = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'id_documento' => 'DOC-1',
        'descricao' => 'Documento 1',
        'status' => 'completed',
        'reduce_level' => 0,
        'extracted_text' => 'Texto já processado',
        'micro_analysis' => 'Resultado já persistido',
    ]);

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $micro->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        customAnalysisPrompt: null,
    );

    $job->handle();

    $micro->refresh();

    expect($micro->status)->toBe('completed');
    expect($micro->micro_analysis)->toBe('Resultado já persistido');
    Http::assertNothingSent();
});

test('map self-healing transition dispatches reduce only once when concurrent lock is denied', function () {
    Queue::fake();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-43.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 2,
        'job_parameters' => [
            'reduceStrategy' => 'batch',
            'aiProvider' => 'openrouter',
            'deepThinkingEnabled' => false,
        ],
    ]);

    $microA = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'id_documento' => 'DOC-1',
        'descricao' => 'Documento 1',
        'status' => 'completed',
        'reduce_level' => 0,
        'extracted_text' => 'Texto 1',
        'micro_analysis' => 'Resultado 1',
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'id_documento' => 'DOC-2',
        'descricao' => 'Documento 2',
        'status' => 'completed',
        'reduce_level' => 0,
        'extracted_text' => 'Texto 2',
        'micro_analysis' => 'Resultado 2',
    ]);

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $microA->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        customAnalysisPrompt: null,
    );

    $method = new ReflectionMethod(MapDocumentAnalysisJob::class, 'ensureMapToReduceTransition');
    $method->setAccessible(true);

    $method->invoke($job, $microA);

    // Simula contenção concorrente: segunda tentativa não consegue o lock.
    $heldLock = Cache::lock("map_to_reduce_transition_{$analysis->id}", 300);
    $heldLock->get();
    $method->invoke($job, $microA);
    $heldLock->release();

    Queue::assertPushed(ReduceDocumentAnalysisJob::class, 1);
});

test('map self-healing marks analysis as failed when all map documents failed', function () {
    Queue::fake();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => User::factory()->create()->id,
        'numero_processo' => '5000000-44.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 2,
        'job_parameters' => [
            'reduceStrategy' => 'batch',
            'aiProvider' => 'openrouter',
            'deepThinkingEnabled' => false,
        ],
    ]);

    $microA = DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'id_documento' => 'DOC-1',
        'descricao' => 'Documento 1',
        'status' => 'failed',
        'reduce_level' => 0,
        'extracted_text' => 'Texto 1',
        'error_message' => 'Erro de API',
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'id_documento' => 'DOC-2',
        'descricao' => 'Documento 2',
        'status' => 'failed',
        'reduce_level' => 0,
        'extracted_text' => 'Texto 2',
        'error_message' => 'Erro de API',
    ]);

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $microA->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        customAnalysisPrompt: null,
    );

    $method = new ReflectionMethod(MapDocumentAnalysisJob::class, 'ensureMapToReduceTransition');
    $method->setAccessible(true);

    $method->invoke($job, $microA);

    $analysis->refresh();

    expect($analysis->status)->toBe('failed');
    expect($analysis->error_message)->toBe('Todos os documentos falharam na fase MAP.');
    Queue::assertNotPushed(ReduceDocumentAnalysisJob::class);
});
