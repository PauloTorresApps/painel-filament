<?php

namespace App\Services;

class AIAnalysisExecutionPipeline
{
    /**
     * @param callable():void $onStart
     * @param callable():string $runAnalysis
     * @param callable():void $onSuccess
     * @param callable(\Exception):void $onError
     * @param callable():array<string,mixed> $metadataProvider
     */
    public function execute(
        string $provider,
        int $totalDocuments,
        bool $isContract,
        callable $onStart,
        callable $runAnalysis,
        callable $onSuccess,
        callable $onError,
        callable $metadataProvider
    ): string {
        try {
            $onStart();

            $this->logInfo('AbstractAIService: Iniciando análise', [
                'provider' => $provider,
                'total_documentos' => $totalDocuments,
                'is_contract' => $isContract,
            ]);

            $result = $runAnalysis();

            $onSuccess();

            $this->logInfo('AbstractAIService: Análise concluída', [
                'provider' => $provider,
                'metadata' => $metadataProvider(),
            ]);

            return $result;
        } catch (\Exception $e) {
            $onError($e);

            $this->logError('AbstractAIService: Erro na análise', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logInfo(string $message, array $context = []): void
    {
        try {
            \Illuminate\Support\Facades\Log::info($message, $context);
        } catch (\Throwable) {
            // Unit tests isolados podem não ter facades inicializadas.
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        try {
            \Illuminate\Support\Facades\Log::error($message, $context);
        } catch (\Throwable) {
            // Unit tests isolados podem não ter facades inicializadas.
        }
    }
}
