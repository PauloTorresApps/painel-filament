<?php

use App\Services\AIAnalysisFlowResolver;

it('resolves contract flow for contract tipo', function () {
    $resolver = new AIAnalysisFlowResolver();

    expect($resolver->resolveFlow(['tipo' => 'Contrato']))
        ->toBe(AIAnalysisFlowResolver::FLOW_CONTRACT)
        ->and($resolver->isContractAnalysis(['tipo' => 'Contrato']))->toBeTrue();
});

it('resolves contract flow for parecer juridico tipo', function () {
    $resolver = new AIAnalysisFlowResolver();

    expect($resolver->resolveFlow(['tipo' => 'Parecer Jurídico']))
        ->toBe(AIAnalysisFlowResolver::FLOW_CONTRACT)
        ->and($resolver->isContractAnalysis(['tipo' => 'Parecer Jurídico']))->toBeTrue();
});

it('resolves simple flow for non contract contexts', function () {
    $resolver = new AIAnalysisFlowResolver();

    expect($resolver->resolveFlow(['tipo' => 'Processo']))
        ->toBe(AIAnalysisFlowResolver::FLOW_SIMPLE)
        ->and($resolver->isContractAnalysis(['tipo' => 'Processo']))->toBeFalse();
});

it('resolves simple flow when tipo is missing or invalid', function () {
    $resolver = new AIAnalysisFlowResolver();

    expect($resolver->resolveFlow([]))->toBe(AIAnalysisFlowResolver::FLOW_SIMPLE)
        ->and($resolver->resolveFlow(['tipo' => ['Contrato']]))->toBe(AIAnalysisFlowResolver::FLOW_SIMPLE)
        ->and($resolver->isContractAnalysis([]))->toBeFalse()
        ->and($resolver->isContractAnalysis(['tipo' => null]))->toBeFalse();
});
