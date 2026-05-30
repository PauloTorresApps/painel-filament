<?php

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;
use App\Strategies\ProcessAnalysis\TextProcessingStrategy;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

it('skips structured call when cooldown is active and falls back to text directly', function () {
    config()->set('services.openrouter.structured_map_enabled', true);
    config()->set('services.openrouter.structured_map_fallback_to_text', true);
    config()->set('services.openrouter.structured_map_failure_cooldown_minutes', 30);

    $micro = new DocumentMicroAnalysis([
        'id' => 77,
        'document_analysis_id' => 42,
        'processing_strategy' => 'pdf_text',
        'mimetype' => 'text/plain',
        'descricao' => 'Documento de teste',
        'extracted_text' => 'conteudo textual',
    ]);

    $provider = mock(AIProviderInterface::class);
    $provider->shouldReceive('getName')->andReturn('openrouter');
    $provider->shouldReceive('analyzeSingleDocumentStructured')->never();
    $provider->shouldReceive('analyzeSingleDocument')
        ->once()
        ->with('PROMPT_DOC', 'conteudo textual', false, 'PROMPT_SYSTEM')
        ->andReturn('analise-livre');

    $cooldownKey = 'map:structured:cooldown:' . sha1('42|openrouter|pdf_text');
    Cache::put($cooldownKey, true, now()->addMinutes(30));

    $strategy = new TextProcessingStrategy(['type' => 'object']);

    $result = $strategy->process(
        $micro,
        $provider,
        'PROMPT_SYSTEM',
        'PROMPT_DOC',
        false
    );

    expect($result)->toBe('analise-livre');
});

it('stores cooldown when structured fails and fallback to text is enabled', function () {
    config()->set('services.openrouter.structured_map_enabled', true);
    config()->set('services.openrouter.structured_map_fallback_to_text', true);
    config()->set('services.openrouter.structured_map_failure_cooldown_minutes', 30);

    $micro = new DocumentMicroAnalysis([
        'id' => 88,
        'document_analysis_id' => 52,
        'processing_strategy' => 'vision',
        'mimetype' => 'text/plain',
        'descricao' => 'Documento de teste 2',
        'extracted_text' => 'conteudo para structured',
    ]);

    $provider = mock(AIProviderInterface::class);
    $provider->shouldReceive('getName')->andReturn('openrouter');
    $provider->shouldReceive('analyzeSingleDocumentStructured')
        ->once()
        ->andThrow(new RuntimeException('structured failed'));
    $provider->shouldReceive('analyzeSingleDocument')
        ->once()
        ->with('PROMPT_DOC', 'conteudo para structured', true, 'PROMPT_SYSTEM')
        ->andReturn('analise-fallback');

    $strategy = new TextProcessingStrategy(['type' => 'object']);

    $result = $strategy->process(
        $micro,
        $provider,
        'PROMPT_SYSTEM',
        'PROMPT_DOC',
        true
    );

    $cooldownKey = 'map:structured:cooldown:' . sha1('52|openrouter|vision');

    expect($result)->toBe('analise-fallback')
        ->and(Cache::has($cooldownKey))->toBeTrue();
});
