<?php

use App\Services\OpenRouterPayloadEnricher;

it('injects temperature when reasoning is disabled', function () {
    $enricher = new OpenRouterPayloadEnricher([
        'temperature' => 0.25,
    ]);

    $payload = $enricher->enrich([
        'model' => 'openrouter/model-x',
    ], false, null);

    expect($payload['temperature'])->toBe(0.25)
        ->and(isset($payload['reasoning']))->toBeFalse();
});

it('injects reasoning config when enabled', function () {
    $enricher = new OpenRouterPayloadEnricher();

    $payload = $enricher->enrich([
        'model' => 'openrouter/model-x',
    ], true, null);

    expect($payload['reasoning'])->toBe([
        'effort' => 'high',
        'exclude' => true,
    ])->and(isset($payload['temperature']))->toBeFalse();
});

it('adds provider routing and transforms from config', function () {
    $enricher = new OpenRouterPayloadEnricher([
        'provider_order' => 'anthropic,google',
        'allow_fallbacks' => true,
        'require_parameters' => true,
        'provider_sort' => 'price',
        'max_price_prompt' => '1.5',
        'max_price_completion' => '2.5',
        'transforms' => 'middle-out,strip-think',
    ]);

    $payload = $enricher->enrich([
        'model' => 'openrouter/model-x',
    ], false, 0.4);

    expect($payload['provider'])->toBeArray()
        ->and($payload['provider']['order'])->toBe(['anthropic', 'google'])
        ->and($payload['provider']['allow_fallbacks'])->toBeTrue()
        ->and($payload['provider']['require_parameters'])->toBeTrue()
        ->and($payload['provider']['sort'])->toBe('price')
        ->and($payload['provider']['max_price']['prompt'])->toBe(1.5)
        ->and($payload['provider']['max_price']['completion'])->toBe(2.5)
        ->and($payload['transforms'])->toBe(['middle-out', 'strip-think'])
        ->and($payload['temperature'])->toBe(0.4);
});

it('does not override pre-existing payload fields', function () {
    $enricher = new OpenRouterPayloadEnricher([
        'temperature' => 0.1,
        'provider_order' => 'anthropic',
        'transforms' => 'middle-out',
    ]);

    $payload = $enricher->enrich([
        'model' => 'openrouter/model-x',
        'temperature' => 0.9,
        'reasoning' => ['effort' => 'low', 'exclude' => false],
        'provider' => ['order' => ['manual']],
        'transforms' => ['custom'],
    ], true, 0.4);

    expect($payload['temperature'])->toBe(0.9)
        ->and($payload['reasoning'])->toBe(['effort' => 'low', 'exclude' => false])
        ->and($payload['provider'])->toBe(['order' => ['manual']])
        ->and($payload['transforms'])->toBe(['custom']);
});
