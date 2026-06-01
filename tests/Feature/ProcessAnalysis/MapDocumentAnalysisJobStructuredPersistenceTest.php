<?php

use App\Jobs\ProcessAnalysis\MapDocumentAnalysisJob;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\ProcessAnalysis\ProcessDecisao;
use App\Models\ProcessAnalysis\ProcessIntimacao;
use App\Models\ProcessAnalysis\ProcessPedido;
use App\Models\User;

$invokePrivate = function (object $instance, string $method, array $arguments = []): mixed {
    $reflection = new ReflectionClass($instance);
    $privateMethod = $reflection->getMethod($method);
    $privateMethod->setAccessible(true);

    return $privateMethod->invokeArgs($instance, $arguments);
};

$makeAnalysisAndMicro = function (): array {
    $user = User::factory()->withoutTwoFactor()->create();

    $analysis = DocumentAnalysis::create([
        'user_id' => $user->id,
        'numero_processo' => '5001234-56.2026.8.24.0001',
        'classe_processual' => 'Procedimento Comum Civel',
        'assuntos' => 'Obrigacao de Fazer, Tutela de Urgencia',
        'status' => 'processing',
        'current_phase' => DocumentAnalysis::PHASE_MAP,
        'job_parameters' => [],
    ]);

    $micro = DocumentMicroAnalysis::create([
        'document_analysis_id' => $analysis->id,
        'document_index' => 1,
        'descricao' => 'Peticao inicial',
        'mimetype' => 'application/pdf',
        'extracted_text' => 'conteudo teste',
        'status' => 'pending',
        'reduce_level' => 0,
        'aggregated_entities' => [
            'partes_mencionadas' => [],
            'valores_monetarios' => [],
            'pontos_chave' => [],
        ],
    ]);

    return [$analysis, $micro];
};

test('persiste entidades estruturadas do MAP no banco', function () use ($invokePrivate, $makeAnalysisAndMicro) {
    [$analysis, $micro] = $makeAnalysisAndMicro();

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $micro->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: []
    );

    $payload = [
        'pedidos_identificados' => [
            [
                'parte' => 'Autora',
                'pedido' => 'Tutela de urgencia para restabelecimento de servico',
                'fundamento' => 'Art. 300 do CPC',
                'status' => 'pendente',
            ],
            [
                'parte' => 'Re',
            ],
        ],
        'decisoes_identificadas' => [
            [
                'evento_ou_id' => '12',
                'data' => '03/05/2026',
                'autoridade' => 'Juizo da 1a Vara Civel',
                'conteudo' => 'Defiro parcialmente a tutela de urgencia.',
                'efeito_juridico' => 'Determinacao de cumprimento imediato',
                'resultado' => 'parcialmente deferido',
            ],
            [
                'evento_ou_id' => '13',
            ],
        ],
        'intimacoes_e_certidoes' => [
            [
                'evento_ou_id' => '15',
                'data' => '2026-05-04',
                'tipo' => 'intimacao',
                'destinatario' => 'Parte autora',
                'conteudo' => 'Manifestar-se sobre documentos juntados.',
                'prazo' => '5 dias',
                'cumprida' => false,
            ],
            [
                'evento_ou_id' => '16',
            ],
        ],
        'lacunas' => [
            'Anexar comprovante de pagamento',
            'Anexar comprovante de pagamento',
            'Juntar procuração atualizada',
        ],
    ];

    $invokePrivate($job, 'persistStructuredAnalystData', [$micro, $payload]);

    expect(ProcessPedido::query()->where('document_micro_analysis_id', $micro->id)->count())->toBe(1)
        ->and(ProcessDecisao::query()->where('document_micro_analysis_id', $micro->id)->count())->toBe(1)
        ->and(ProcessIntimacao::query()->where('document_micro_analysis_id', $micro->id)->count())->toBe(1);

    $pedido = ProcessPedido::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();
    $decisao = ProcessDecisao::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();
    $intimacao = ProcessIntimacao::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();

    expect($pedido->document_analysis_id)->toBe($analysis->id)
        ->and($decisao->data?->toDateString())->toBe('2026-05-03')
        ->and($intimacao->data?->toDateString())->toBe('2026-05-04');

    $micro->refresh();

    expect($micro->aggregated_entities['lacunas'] ?? null)->toBe([
        'Anexar comprovante de pagamento',
        'Juntar procuração atualizada',
    ]);
});

test('extrai analista_json e persiste dados no modo texto livre', function () use ($invokePrivate, $makeAnalysisAndMicro) {
    [$analysis, $micro] = $makeAnalysisAndMicro();

    $job = new MapDocumentAnalysisJob(
        microAnalysisId: $micro->id,
        aiProvider: 'openrouter',
        deepThinkingEnabled: false,
        contextoDados: []
    );

    $analysisText = <<<'TEXT'
Analise em markdown.

<analista_json>
```json
{
  "pedidos_identificados": [
    {
      "pedido": "Condenacao da parte re ao cumprimento da obrigacao"
    }
  ],
  "decisoes_identificadas": [
    {
      "conteudo": "Indefiro o pedido liminar.",
      "data": "data invalida"
    }
  ],
  "intimacoes_e_certidoes": [
    {
      "tipo": "certidao",
      "conteudo": "Certifico o decurso do prazo.",
      "cumprida": true
    }
  ],
  "lacunas": [
    "Juntar comprovante de representacao"
  ]
}
```
</analista_json>
TEXT;

    $parsed = $invokePrivate($job, 'extractEmbeddedAnalystJson', [$analysisText, $analysis]);

    expect($parsed)->toBeArray()
        ->and($parsed['identificacao_processo']['numero_processo'] ?? null)->toBe($analysis->numero_processo)
        ->and($parsed['identificacao_processo']['classe_processual'] ?? null)->toBe($analysis->classe_processual)
        ->and($parsed['identificacao_processo']['assuntos'] ?? null)->toBe([
            'Obrigacao de Fazer',
            'Tutela de Urgencia',
        ]);

    $invokePrivate($job, 'persistStructuredAnalystData', [$micro, $parsed]);

    $pedido = ProcessPedido::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();
    $decisao = ProcessDecisao::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();
    $intimacao = ProcessIntimacao::query()->where('document_micro_analysis_id', $micro->id)->firstOrFail();

    expect($pedido->pedido)->toContain('Condenacao')
        ->and($decisao->data)->toBeNull()
        ->and($intimacao->cumprida)->toBeTrue();
});

test('normaliza chaves obrigatorias de entidades quando aggregated_entities e parcial', function () use ($makeAnalysisAndMicro) {
    [, $micro] = $makeAnalysisAndMicro();

    $micro->update([
        'aggregated_entities' => [
            'lacunas' => ['Documento ilegivel'],
        ],
    ]);

    $entities = $micro->fresh()->getEntities();

    expect($entities)->toHaveKeys([
        'partes_mencionadas',
        'valores_monetarios',
        'pontos_chave',
    ])
        ->and($entities['partes_mencionadas'])->toBeArray()->toBe([])
        ->and($entities['valores_monetarios'])->toBeArray()->toBe([])
        ->and($entities['pontos_chave'])->toBeArray()->toBe([]);
});
