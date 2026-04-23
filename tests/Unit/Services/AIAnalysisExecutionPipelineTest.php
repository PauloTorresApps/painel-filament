<?php

use App\Services\AIAnalysisExecutionPipeline;

it('runs start analysis success callbacks in order and returns result', function () {
    $pipeline = new AIAnalysisExecutionPipeline();
    $calls = [];
    $metadata = ['tokens' => 10];

    $result = $pipeline->execute(
        provider: 'OpenRouter',
        totalDocuments: 2,
        isContract: false,
        onStart: function () use (&$calls): void {
            $calls[] = 'start';
        },
        runAnalysis: function () use (&$calls): string {
            $calls[] = 'run';
            return 'ok';
        },
        onSuccess: function () use (&$calls): void {
            $calls[] = 'success';
        },
        onError: function () use (&$calls): void {
            $calls[] = 'error';
        },
        metadataProvider: function () use (&$calls, $metadata): array {
            $calls[] = 'metadata';
            return $metadata;
        }
    );

    expect($result)->toBe('ok')
        ->and($calls)->toBe(['start', 'run', 'success', 'metadata']);
});

it('runs error callback and rethrows exception when analysis fails', function () {
    $pipeline = new AIAnalysisExecutionPipeline();
    $calls = [];

    try {
        $pipeline->execute(
            provider: 'OpenRouter',
            totalDocuments: 1,
            isContract: true,
            onStart: function () use (&$calls): void {
                $calls[] = 'start';
            },
            runAnalysis: function () use (&$calls): string {
                $calls[] = 'run';
                throw new Exception('falha');
            },
            onSuccess: function () use (&$calls): void {
                $calls[] = 'success';
            },
            onError: function (Exception $e) use (&$calls): void {
                $calls[] = 'error:' . $e->getMessage();
            },
            metadataProvider: function () use (&$calls): array {
                $calls[] = 'metadata';
                return [];
            }
        );

        $this->fail('A exceção esperada não foi lançada.');
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('falha')
            ->and($calls)->toBe(['start', 'run', 'error:falha']);
    }
});

it('does not call success or metadata providers on failure', function () {
    $pipeline = new AIAnalysisExecutionPipeline();
    $calls = [];

    try {
        $pipeline->execute(
            provider: 'OpenRouter',
            totalDocuments: 1,
            isContract: false,
            onStart: function () use (&$calls): void {
                $calls[] = 'start';
            },
            runAnalysis: function () use (&$calls): string {
                $calls[] = 'run';
                throw new Exception('boom');
            },
            onSuccess: function () use (&$calls): void {
                $calls[] = 'success';
            },
            onError: function () use (&$calls): void {
                $calls[] = 'error';
            },
            metadataProvider: function () use (&$calls): array {
                $calls[] = 'metadata';
                return [];
            }
        );
    } catch (Exception) {
        // esperado
    }

    expect($calls)->toBe(['start', 'run', 'error']);
});
