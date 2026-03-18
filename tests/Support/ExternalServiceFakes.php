<?php

use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\System;
use Illuminate\Support\Facades\Http;

function setupOpenRouterTestingConfig(): void
{
    config()->set('services.openrouter.api_key', 'test-api-key');
    config()->set('services.openrouter.api_url', 'https://openrouter.test/api/v1');
    config()->set('services.openrouter.timeout', 30);
}

function ensureContractsSystemAndModel(): array
{
    $system = System::query()->firstOrCreate(
        ['name' => 'Contratos'],
        ['description' => 'Sistema de contratos', 'is_active' => true]
    );

    $model = AiModel::query()->firstOrCreate(
        ['provider' => 'openrouter', 'model_id' => 'openrouter/test-model'],
        [
            'name' => 'Test Model',
            'description' => 'Model for tests',
            'is_active' => true,
            'supports_reasoning' => false,
            'supports_vision' => false,
        ]
    );

    return [$system, $model];
}

function createDefaultContractPrompt(
    int $systemId,
    int $modelId,
    string $promptType,
    string $title,
    string $content,
    float $temperature = 0.3
): AiPrompt {
    return AiPrompt::query()->create([
        'system_id' => $systemId,
        'prompt_type' => $promptType,
        'title' => $title,
        'content' => $content,
        'ai_provider' => 'openrouter',
        'ai_model_id' => $modelId,
        'deep_thinking_enabled' => false,
        'analysis_strategy' => 'evolutionary',
        'temperature' => $temperature,
        'is_active' => true,
        'is_default' => true,
    ]);
}

function fakeOpenRouterSingleResponse(string $content, int $promptTokens = 100, int $completionTokens = 70): void
{
    Http::fake([
        'https://openrouter.test/api/v1/chat/completions' => Http::response([
            'id' => 'gen-1',
            'model' => 'openrouter/test-model',
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
        ], 200),
    ]);
}

function fakeOpenRouterSequence(array $contents): void
{
    $sequence = Http::sequence();

    foreach ($contents as $index => $content) {
        $sequence->push([
            'id' => 'seq-' . ($index + 1),
            'model' => 'openrouter/test-model',
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 70,
            ],
        ], 200);
    }

    Http::fake([
        'https://openrouter.test/api/v1/chat/completions' => $sequence,
    ]);
}

function mockEprocDocumentsSuccess(string $documentId, string $documentBase64): void
{
    $eprocMock = Mockery::mock('overload:App\\Services\\EprocService');
    $eprocMock->shouldReceive('consultarDocumentosProcesso')
        ->once()
        ->andReturn([
            'Body' => [
                'respostaConsultarDocumentosProcesso' => [
                    'documentos' => [
                        [
                            'idDocumento' => $documentId,
                            'conteudo' => [
                                'conteudo' => $documentBase64,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
}

function mockEprocThrows(string $message): void
{
    $eprocMock = Mockery::mock('overload:App\\Services\\EprocService');
    $eprocMock->shouldReceive('consultarDocumentosProcesso')
        ->once()
        ->andThrow(new Exception($message));
}

function mockPdfToTextExtraction(string $text, bool $isScanned = false): void
{
    $pdfServiceMock = Mockery::mock('overload:App\\Services\\PdfToTextService');
    $pdfServiceMock->shouldReceive('extractTextWithMetadata')
        ->once()
        ->andReturn([
            'text' => $text,
            'is_scanned' => $isScanned,
            'page_count' => 1,
        ]);
}
