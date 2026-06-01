<?php

use App\Jobs\ProcessAnalysis\CheckReduceLevelCompletionJob;
use App\Jobs\ProcessAnalysis\MapDocumentAnalysisJob;
use App\Jobs\ProcessAnalysis\ReduceDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function invokePrivateMethod(object $instance, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionClass($instance);
    $privateMethod = $reflection->getMethod($method);
    $privateMethod->setAccessible(true);

    return $privateMethod->invokeArgs($instance, $arguments);
}

it('aborts in map self-healing when failure rate exceeds circuit breaker threshold', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5001000-11.2026.8.24.0001',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 4,
        'processed_documents_count' => 0,
        'job_parameters' => [],
    ]);

    $microCompleted = DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Documento 1',
        'mimetype' => 'application/pdf',
        'extracted_text' => 'texto base',
        'micro_analysis' => 'analise concluida',
        'status' => 'completed',
        'reduce_level' => 0,
        'aggregated_entities' => [
            'partes_mencionadas' => [],
            'valores_monetarios' => [],
            'pontos_chave' => [],
        ],
    ]);

    foreach ([2, 3, 4] as $index) {
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => "Documento {$index}",
            'mimetype' => 'application/pdf',
            'extracted_text' => 'texto base',
            'status' => 'failed',
            'error_message' => 'Falha simulada',
            'reduce_level' => 0,
            'aggregated_entities' => [
                'partes_mencionadas' => [],
                'valores_monetarios' => [],
                'pontos_chave' => [],
            ],
        ]);
    }

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $microCompleted->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: []
    );

    invokePrivateMethod($job, 'ensureMapToReduceTransition', [$microCompleted->fresh()]);

    $analysis->refresh();

    expect($analysis->status)->toBe('failed')
        ->and((string) $analysis->error_message)->toContain('Análise abortada: 3 de 4 documentos falharam (75%)');
});

it('aborts in reduce completion check when failure rate exceeds circuit breaker threshold', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5002000-22.2026.8.24.0001',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 4,
        'processed_documents_count' => 4,
        'job_parameters' => [],
    ]);

    foreach ([1, 2] as $index) {
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => "Reduce {$index}",
            'micro_analysis' => 'consolidacao concluida',
            'status' => 'completed',
            'reduce_level' => 1,
            'aggregated_entities' => [
                'partes_mencionadas' => [],
                'valores_monetarios' => [],
                'pontos_chave' => [],
            ],
        ]);
    }

    foreach ([3, 4] as $index) {
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => "Reduce {$index}",
            'status' => 'failed',
            'error_message' => 'Falha simulada no reduce',
            'reduce_level' => 1,
            'aggregated_entities' => [
                'partes_mencionadas' => [],
                'valores_monetarios' => [],
                'pontos_chave' => [],
            ],
        ]);
    }

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: '',
        aiModelId: null,
        completedReduceLevel: 1
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('failed')
        ->and((string) $analysis->error_message)->toContain('Consolidação abortada no nível 1: 2 de 4 batches falharam (50%)');
});

it('continues map self-healing transition when failure rate is below threshold', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    Queue::fake();

    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5003000-33.2026.8.24.0001',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'total_documents' => 4,
        'processed_documents_count' => 0,
        'job_parameters' => [
            'reduceStrategy' => 'batch',
            'promptTemplate' => '',
        ],
    ]);

    foreach ([1, 2, 3] as $index) {
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => "Documento {$index}",
            'mimetype' => 'application/pdf',
            'extracted_text' => 'texto base',
            'micro_analysis' => 'analise concluida',
            'status' => 'completed',
            'reduce_level' => 0,
            'aggregated_entities' => [
                'partes_mencionadas' => [],
                'valores_monetarios' => [],
                'pontos_chave' => [],
            ],
        ]);
    }

    $microFailed = DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 4,
        'descricao' => 'Documento 4',
        'mimetype' => 'application/pdf',
        'extracted_text' => 'texto base',
        'status' => 'failed',
        'error_message' => 'Falha simulada',
        'reduce_level' => 0,
        'aggregated_entities' => [
            'partes_mencionadas' => [],
            'valores_monetarios' => [],
            'pontos_chave' => [],
        ],
    ]);

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $microFailed->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: []
    );

    invokePrivateMethod($job, 'ensureMapToReduceTransition', [$microFailed->fresh()]);

    $analysis->refresh();

    expect($analysis->status)->toBe('processing')
        ->and($analysis->error_message)->toBeNull();

    Queue::assertPushed(ReduceDocumentAnalysisJob::class, function (ReduceDocumentAnalysisJob $queued) use ($analysis) {
        return $queued->documentAnalysisId === $analysis->id
            && $queued->currentReduceLevel === 1;
    });
});

it('continues reduce completion flow when failure rate is below threshold', function () {
    config()->set('analysis.circuit_breaker.failure_threshold', 0.25);
    config()->set('analysis.circuit_breaker.min_jobs', 4);

    Queue::fake();

    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5004000-44.2026.8.24.0001',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 12,
        'processed_documents_count' => 12,
        'job_parameters' => [],
    ]);

    foreach (range(1, 11) as $index) {
        DocumentMicroAnalysis::create([
            'document_analysis_id' => $analysis->id,
            'document_index' => $index,
            'descricao' => "Reduce {$index}",
            'micro_analysis' => 'consolidacao concluida',
            'status' => 'completed',
            'reduce_level' => 1,
            'aggregated_entities' => [
                'partes_mencionadas' => [],
                'valores_monetarios' => [],
                'pontos_chave' => [],
            ],
        ]);
    }

    DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 12,
        'descricao' => 'Reduce 12',
        'status' => 'failed',
        'error_message' => 'Falha simulada no reduce',
        'reduce_level' => 1,
        'aggregated_entities' => [
            'partes_mencionadas' => [],
            'valores_monetarios' => [],
            'pontos_chave' => [],
        ],
    ]);

    $job = new CheckReduceLevelCompletionJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        promptTemplate: '',
        aiModelId: null,
        completedReduceLevel: 1
    );

    $job->handle();

    $analysis->refresh();

    expect($analysis->status)->toBe('processing')
        ->and($analysis->error_message)->toBeNull();

    Queue::assertPushed(ReduceDocumentAnalysisJob::class, function (ReduceDocumentAnalysisJob $queued) use ($analysis) {
        return $queued->documentAnalysisId === $analysis->id
            && $queued->currentReduceLevel === 2;
    });
});
