<?php

use App\Jobs\AnalyzeProcessDocuments;
use App\Jobs\DispatchMapPhaseJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;

test('analyze process documents creates micro analyses and dispatches map phase', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();

    $documentContent = base64_encode('%PDF-1.4 fake content');

    $eprocMock = Mockery::mock('overload:App\\Services\\EprocService');
    $eprocMock->shouldReceive('consultarDocumentosProcesso')
        ->once()
        ->andReturn([
            'Body' => [
                'respostaConsultarDocumentosProcesso' => [
                    'documentos' => [
                        [
                            'idDocumento' => 'DOC-1',
                            'conteudo' => [
                                'conteudo' => $documentContent,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    mockPdfToTextExtraction('Texto extraido do PDF', false);

    $job = new AnalyzeProcessDocuments(
        userId: $user->id,
        numeroProcesso: '5000000-00.2026.4.04.0000',
        documentos: [
            [
                'idDocumento' => 'DOC-1',
                'descricao' => 'Peticao Inicial',
                'conteudo' => [
                    'mimetype' => 'application/pdf',
                ],
            ],
        ],
        contextoDados: [
            'classeProcessualNome' => 'Procedimento Comum',
            'assunto' => [],
        ],
        promptTemplate: 'Prompt final',
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        userLogin: 'usuario',
        senha: 'senha',
        judicialUserId: 1,
    );

    $job->handle();

    $analysis = DocumentAnalysis::query()->first();

    expect($analysis)->not->toBeNull();
    expect($analysis->status)->toBe('processing');
    expect($analysis->total_documents)->toBe(1);

    $micro = DocumentMicroAnalysis::query()->first();

    expect($micro)->not->toBeNull();
    expect($micro->id_documento)->toBe('DOC-1');
    expect($micro->status)->toBe('pending');
    expect($micro->processing_strategy)->toBe('pdf_text');
    expect($micro->extracted_text)->toBe('Texto extraido do PDF');

    Queue::assertPushed(DispatchMapPhaseJob::class, function (DispatchMapPhaseJob $dispatched) use ($analysis) {
        return $dispatched->analysisId === $analysis->id;
    });
});

test('analyze process documents job is configured for retry and uniqueness', function () {
    $job = new AnalyzeProcessDocuments(
        userId: 1,
        numeroProcesso: '5000000-11.2026.4.04.0000',
        documentos: [],
        contextoDados: [],
        promptTemplate: 'Prompt final',
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        userLogin: 'usuario',
        senha: 'senha',
        judicialUserId: 1,
    );

    expect($job->tries)->toBeGreaterThan(1);
    expect($job->timeout)->toBeGreaterThan(0);
    expect($job->uniqueFor)->toBeGreaterThan(0);
    expect($job->uniqueId())->toContain('analyze_process_');
});
