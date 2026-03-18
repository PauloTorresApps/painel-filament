<?php

use App\Jobs\DispatchMapPhaseJob;
use App\Jobs\MapDocumentAnalysisJob;
use App\Jobs\RefineReduceJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

test('dispatch map phase moves analysis to map and batches map jobs', function () {
    Bus::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-21.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_DOWNLOAD,
        'total_documents' => 2,
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 0,
        'id_documento' => 'DOC-1',
        'descricao' => 'Documento 1',
        'mimetype' => 'application/pdf',
        'extracted_text' => str_repeat('A', 500),
        'status' => 'pending',
        'reduce_level' => 0,
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'id_documento' => 'DOC-2',
        'descricao' => 'Documento 2',
        'mimetype' => 'application/pdf',
        'extracted_text' => str_repeat('B', 500),
        'status' => 'pending',
        'reduce_level' => 0,
    ]);

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

    expect($analysis->current_phase)->toBe(DocumentAnalysis::PHASE_MAP);
    expect($analysis->progress_message)->toContain('Analisando documentos individualmente');

    Bus::assertBatched(function ($batch) {
        if ($batch->name !== null && !str_starts_with($batch->name, 'map_analysis_')) {
            return false;
        }

        return count($batch->jobs) === 2
            && $batch->jobs[0] instanceof MapDocumentAnalysisJob
            && $batch->jobs[1] instanceof MapDocumentAnalysisJob;
    });
});

test('dispatch map phase fails analysis when no map inputs are available', function () {
    Bus::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-22.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_DOWNLOAD,
        'total_documents' => 0,
    ]);

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
    expect($analysis->error_message)->toContain('Nenhum documento pôde ser baixado com sucesso');

    Bus::assertNothingBatched();
});

test('dispatch map phase jumps directly to reduce when map was already completed', function () {
    Bus::fake();

    $user = User::factory()->create();

    $analysis = DocumentAnalysis::query()->create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-23.2026.4.04.0000',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_DOWNLOAD,
        'total_documents' => 1,
        'job_parameters' => [
            'promptTemplate' => 'Prompt final para consolidacao',
        ],
    ]);

    DocumentMicroAnalysis::query()->create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 0,
        'id_documento' => 'DOC-MAP-READY',
        'descricao' => 'Documento consolidado',
        'mimetype' => 'application/pdf',
        'extracted_text' => 'Texto ja analisado',
        'micro_analysis' => 'Resultado de micro-analise',
        'status' => 'completed',
        'reduce_level' => 0,
    ]);

    $job = new DispatchMapPhaseJob(
        analysisId: $analysis->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: [],
        aiModelId: null,
        userId: $user->id,
        reduceStrategy: 'refine',
        mapModelId: null,
    );

    $job->handle();

    Bus::assertDispatched(RefineReduceJob::class, function (RefineReduceJob $dispatched) use ($analysis) {
        return $dispatched->documentAnalysisId === $analysis->id;
    });

    Bus::assertNothingBatched();
});
