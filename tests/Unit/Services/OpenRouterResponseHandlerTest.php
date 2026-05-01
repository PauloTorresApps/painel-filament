<?php

use App\Services\OpenRouterResponseHandler;

it('parses text and usage from a standard response', function () {
    $handler = new OpenRouterResponseHandler();

    $result = $handler->parse([
        'id' => 'gen_123',
        'model' => 'openrouter/model-x',
        'usage' => [
            'prompt_tokens' => 12,
            'completion_tokens' => 8,
        ],
        'choices' => [
            [
                'message' => [
                    'content' => 'texto final',
                ],
            ],
        ],
        'annotations' => [['key' => 'value']],
    ], 'texto', false);

    expect($result['text'])->toBe('texto final')
        ->and($result['total_tokens'])->toBe(20)
        ->and($result['usage'])->toBeArray()
        ->and($result['usage']['prompt_tokens'])->toBe(12)
        ->and($result['usage']['completion_tokens'])->toBe(8)
        ->and($result['model'])->toBe('openrouter/model-x')
        ->and($result['generation_id'])->toBe('gen_123')
        ->and($result['annotations'])->toBeArray();
});

it('parses text content from content blocks', function () {
    $handler = new OpenRouterResponseHandler();

    $result = $handler->parse([
        'choices' => [
            [
                'message' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'linha 1'],
                        ['type' => 'image', 'text' => 'ignorar'],
                        ['type' => 'text', 'text' => 'linha 2'],
                    ],
                ],
            ],
        ],
    ], 'imagem', false);

    expect($result['text'])->toBe("linha 1\nlinha 2")
        ->and($result['total_tokens'])->toBe(0)
        ->and($result['usage'])->toBeNull();
});

it('falls back to reasoning when content is empty', function () {
    $handler = new OpenRouterResponseHandler();

    $result = $handler->parse([
        'choices' => [
            [
                'message' => [
                    'content' => '',
                    'reasoning' => 'raciocinio util',
                ],
            ],
        ],
    ], 'texto', true);

    expect($result['text'])->toBe('raciocinio util');
});

it('throws when no usable content is present', function () {
    $handler = new OpenRouterResponseHandler();

    $handler->parse([
        'id' => 'gen_empty',
        'model' => 'openrouter/model-x',
        'choices' => [
            [
                'message' => [
                    'content' => '',
                ],
            ],
        ],
    ], 'texto', false);
})->throws(Exception::class, 'A API OpenRouter retornou uma resposta vazia para texto. Tente novamente em alguns instantes.');
