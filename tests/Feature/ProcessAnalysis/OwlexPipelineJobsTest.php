<?php

use App\Jobs\ProcessAnalysis\BuildChronologyJob;
use App\Jobs\ProcessAnalysis\BuildDesignerBriefJob;
use App\Jobs\ProcessAnalysis\BuildStructuredOpinionJob;
use App\Jobs\ProcessAnalysis\RunProcessEngineJob;
use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\ProcessAnalysis\ProcessEngineSnapshot;
use App\Models\ProcessAnalysis\ProcessEvent;
use App\Models\ProcessAnalysis\ProcessInventoryItem;
use App\Models\ProcessAnalysis\ProcessOpportunity;
use App\Models\ProcessAnalysis\ProcessPedido;
use App\Models\ProcessAnalysis\ProcessRisk;
use App\Models\ProcessAnalysis\ProcessStructuredOpinion;
use App\Models\System;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function ensureOwlexPromptsForSystemOne(): void
{
    System::query()->updateOrInsert(
        ['id' => 1],
        [
            'name' => 'Judicial',
            'description' => 'Sistema judicial principal',
            'is_active' => true,
            'updated_at' => now(),
            'created_at' => now(),
        ]
    );

    $model = AiModel::create([
        'name' => 'Modelo OWLEX Teste',
        'provider' => 'openrouter',
        'model_id' => 'openai/gpt-4o-mini-owlex',
        'description' => 'Modelo para testes OWLEX',
        'is_active' => true,
        'supports_reasoning' => true,
        'supports_vision' => true,
    ]);

    $templates = [
        AiPrompt::TYPE_CHRONOLOGY_BUILDER => '[PROMPT_CHRONOLOGY] :evento :tipo :duracao_dias :data_inicio :data_fim',
        AiPrompt::TYPE_ENGINE_INTELLIGENCE => '[PROMPT_ENGINE] :tipo :score :contexto',
        AiPrompt::TYPE_PARECER_STRUCTURED => '[PROMPT_PARECER] :secao :numero_processo :risco_score :urgencia_score :confiabilidade_score :cenario',
        AiPrompt::TYPE_DESIGNER_BRIEF => '[PROMPT_DESIGNER] :secao :numero_processo :risk_score :urgency_score',
    ];

    foreach ($templates as $type => $content) {
        AiPrompt::create([
            'system_id' => 1,
            'prompt_type' => $type,
            'title' => 'Prompt ' . $type,
            'content' => $content,
            'ai_provider' => 'openrouter',
            'ai_model_id' => $model->id,
            'deep_thinking_enabled' => true,
            'analysis_strategy' => 'evolutionary',
            'temperature' => 0.3,
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}

function createOwlexBaseAnalysis(): DocumentAnalysis
{
    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5009999-12.2026.8.24.0001',
        'classe_processual' => 'Procedimento Comum Civel',
        'assuntos' => 'Obrigacao de Fazer',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_REDUCE,
        'total_documents' => 2,
        'processed_documents_count' => 2,
        'job_parameters' => [],
    ]);

    $firstMicro = DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'id_documento' => 'doc-1',
        'descricao' => 'Peticao inicial',
        'mimetype' => 'application/pdf',
        'micro_analysis' => 'Analise 1',
        'extracted_text' => str_repeat('A', 250),
        'status' => 'completed',
        'reduce_level' => 0,
        'timeline_events' => [
            'eventos' => [
                [
                    'evento_ou_id' => '1',
                    'tipo' => 'peticao',
                    'descricao' => 'Distribuicao da inicial',
                    'data' => '2026-01-01',
                    'relevancia' => 'alta',
                ],
            ],
        ],
        'aggregated_entities' => [
            'partes_mencionadas' => ['Autor'],
            'valores_monetarios' => ['R$ 10.000,00'],
            'pontos_chave' => ['Pedido de tutela'],
        ],
    ]);

    DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 2,
        'id_documento' => 'doc-2',
        'descricao' => 'Despacho judicial',
        'mimetype' => 'application/pdf',
        'micro_analysis' => 'Analise 2',
        'extracted_text' => str_repeat('B', 280),
        'status' => 'completed',
        'reduce_level' => 0,
        'timeline_events' => [
            'eventos' => [
                [
                    'evento_ou_id' => '2',
                    'tipo' => 'intimacao',
                    'descricao' => 'Intimacao para manifestacao no prazo de 5 dias',
                    'data' => '2026-04-20',
                    'prazo' => '5 dias',
                    'relevancia' => 'alta',
                ],
            ],
        ],
        'aggregated_entities' => [
            'partes_mencionadas' => ['Reu'],
            'valores_monetarios' => [],
            'pontos_chave' => ['Prazo para resposta'],
        ],
    ]);

    ProcessInventoryItem::create([
        'document_analysis_id' => $analysis->id,
        'document_micro_analysis_id' => $firstMicro->id,
        'evento_ou_id' => 'INV-1',
        'data' => '2026-01-01',
        'tipo' => 'anexo',
        'classificacao' => 'outro',
        'resumo' => 'Documento com texto incompleto para OCR',
        'relevancia' => 50,
        'duplicado_de_item_id' => null,
        'legivel' => false,
        'completo' => true,
        'observacoes' => 'Falha parcial de legibilidade',
        'content_hash' => hash('sha256', 'inv-1'),
    ]);

    ProcessPedido::create([
        'document_analysis_id' => $analysis->id,
        'document_micro_analysis_id' => $firstMicro->id,
        'parte' => 'Autora',
        'pedido' => 'Tutela de urgencia',
        'fundamentacao' => 'Art. 300 do CPC',
        'status' => 'pendente',
    ]);

    return $analysis;
}

it('executes owlex pipeline stages with db prompts and idempotent finalization', function () {
    Queue::fake();

    ensureOwlexPromptsForSystemOne();
    $analysis = createOwlexBaseAnalysis();

    (new BuildChronologyJob($analysis->id))->handle();

    expect(ProcessEvent::where('document_analysis_id', $analysis->id)->count())->toBe(2);

    Queue::assertPushed(RunProcessEngineJob::class, function (RunProcessEngineJob $job) use ($analysis) {
        return $job->analysisId === $analysis->id;
    });

    (new RunProcessEngineJob($analysis->id))->handle();

    expect(ProcessEngineSnapshot::where('document_analysis_id', $analysis->id)->count())->toBe(1)
        ->and(ProcessRisk::where('document_analysis_id', $analysis->id)->count())->toBeGreaterThan(0)
        ->and(ProcessOpportunity::where('document_analysis_id', $analysis->id)->count())->toBeGreaterThan(0);

    Queue::assertPushed(BuildStructuredOpinionJob::class, function (BuildStructuredOpinionJob $job) use ($analysis) {
        return $job->analysisId === $analysis->id;
    });

    (new BuildStructuredOpinionJob($analysis->id))->handle();

    $opinion = ProcessStructuredOpinion::where('document_analysis_id', $analysis->id)->firstOrFail();

    expect($opinion->sumario_executivo)->toContain('[PROMPT_PARECER]')
        ->and($analysis->actionPlanItems()->count())->toBeGreaterThan(0);

    Queue::assertPushed(BuildDesignerBriefJob::class, function (BuildDesignerBriefJob $job) use ($analysis) {
        return $job->analysisId === $analysis->id;
    });

    (new BuildDesignerBriefJob($analysis->id))->handle();

    $analysis->refresh();
    $metadata = (array) ($analysis->analysis_ai_metadata ?? []);
    $owlex = (array) ($metadata['owlex'] ?? []);

    expect($analysis->status)->toBe('completed')
        ->and($analysis->current_phase)->toBe(DocumentAnalysis::PHASE_COMPLETED)
        ->and((string) ($owlex['designer_brief']['headline'] ?? ''))->toContain('[PROMPT_DESIGNER]')
        ->and((string) ($owlex['finalized_at'] ?? ''))->not->toBe('');

    $firstFinalizedAt = (string) ($owlex['finalized_at'] ?? '');

    (new BuildDesignerBriefJob($analysis->id))->handle();

    $analysis->refresh();
    $metadataAfterRetry = (array) ($analysis->analysis_ai_metadata ?? []);
    $owlexAfterRetry = (array) ($metadataAfterRetry['owlex'] ?? []);

    expect((string) ($owlexAfterRetry['finalized_at'] ?? ''))->toBe($firstFinalizedAt);
});

it('skips owlex jobs when phase is already advanced', function () {
    Queue::fake();

    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5000000-00.2026.8.24.0001',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
        'job_parameters' => [],
    ]);

    (new BuildChronologyJob($analysis->id))->handle();
    (new RunProcessEngineJob($analysis->id))->handle();
    (new BuildStructuredOpinionJob($analysis->id))->handle();

    Queue::assertNotPushed(RunProcessEngineJob::class);
    Queue::assertNotPushed(BuildStructuredOpinionJob::class);
    Queue::assertNotPushed(BuildDesignerBriefJob::class);

    expect(ProcessEvent::where('document_analysis_id', $analysis->id)->count())->toBe(0)
        ->and(ProcessEngineSnapshot::where('document_analysis_id', $analysis->id)->count())->toBe(0)
        ->and(ProcessStructuredOpinion::where('document_analysis_id', $analysis->id)->count())->toBe(0);
});
