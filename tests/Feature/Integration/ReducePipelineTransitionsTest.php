<?php

use App\Jobs\CheckReduceLevelCompletionJob;
use App\Jobs\ReduceBatchJob;
use App\Jobs\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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
