<?php

namespace App\Services;

use OpenTelemetry\API\Globals;

class OtelMetricsService
{
    private mixed $jobExecutionsCounter = null;
    private mixed $jobDurationHistogram = null;
    private mixed $jobFailuresCounter = null;
    private mixed $dbQueryDurationHistogram = null;
    private mixed $aiApiCallsCounter = null;
    private mixed $aiApiDurationHistogram = null;
    private mixed $aiTokensCounter = null;
    private mixed $externalApiCallsCounter = null;
    private mixed $externalApiDurationHistogram = null;
    private mixed $documentExtractionCounter = null;
    private mixed $documentExtractionDurationHistogram = null;
    private mixed $documentProcessedCharsCounter = null;
    private mixed $notificationCounter = null;
    private mixed $httpRequestsCounter = null;
    private mixed $httpRequestDurationHistogram = null;
    private mixed $pipelineNodeExecutionsCounter = null;
    private mixed $pipelineNodeDurationHistogram = null;
    private mixed $pipelineNodeTokensCounter = null;
    private mixed $pipelineNodeCostCounter = null;

    public function recordJobExecution(string $jobName, string $status, string $queue, float $durationMs): void
    {
        try {
            $attributes = [
                'job.name' => $jobName,
                'job.status' => $status,
                'queue.name' => $queue,
            ];

            $this->getJobExecutionsCounter()->add(1, $attributes);
            $this->getJobDurationHistogram()->record($durationMs, $attributes);

            if ($status === 'failed') {
                $this->getJobFailuresCounter()->add(1, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    public function recordDbQuery(float $durationMs, string $connection): void
    {
        try {
            $this->getDbQueryDurationHistogram()->record($durationMs, [
                'db.connection' => $connection,
            ]);
        } catch (\Throwable) {
        }
    }

    public function recordAiApiCall(
        string $provider,
        string $model,
        string $callType,
        string $status,
        float $durationMs,
        int $totalTokens = 0
    ): void {
        try {
            $attributes = [
                'ai.provider' => $provider,
                'ai.model' => $model,
                'ai.call_type' => $callType,
                'ai.status' => $status,
            ];

            $this->getAiApiCallsCounter()->add(1, $attributes);
            $this->getAiApiDurationHistogram()->record($durationMs, $attributes);

            if ($totalTokens > 0) {
                $this->getAiTokensCounter()->add($totalTokens, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    public function recordExternalApiCall(string $system, string $operation, string $status, float $durationMs): void
    {
        try {
            $attributes = [
                'external.system' => $system,
                'external.operation' => $operation,
                'external.status' => $status,
            ];

            $this->getExternalApiCallsCounter()->add(1, $attributes);
            $this->getExternalApiDurationHistogram()->record($durationMs, $attributes);
        } catch (\Throwable) {
        }
    }

    public function recordDocumentExtraction(
        string $operation,
        string $format,
        string $status,
        float $durationMs,
        int $charsExtracted = 0
    ): void {
        try {
            $attributes = [
                'document.operation' => $operation,
                'document.format' => $format,
                'document.status' => $status,
            ];

            $this->getDocumentExtractionCounter()->add(1, $attributes);
            $this->getDocumentExtractionDurationHistogram()->record($durationMs, $attributes);

            if ($charsExtracted > 0) {
                $this->getDocumentProcessedCharsCounter()->add($charsExtracted, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    public function recordNotification(string $status, bool $hasUser): void
    {
        try {
            $this->getNotificationCounter()->add(1, [
                'notification.status' => $status,
                'notification.has_user' => $hasUser,
            ]);
        } catch (\Throwable) {
        }
    }

    public function recordHttpRequest(string $method, string $route, int $statusCode, float $durationMs): void
    {
        try {
            $attributes = [
                'http.request.method' => $method,
                'http.route' => $route,
                'http.response.status_code' => $statusCode,
            ];

            $this->getHttpRequestsCounter()->add(1, $attributes);
            $this->getHttpRequestDurationHistogram()->record($durationMs, $attributes);
        } catch (\Throwable) {
        }
    }

    public function recordPipelineNodeExecution(
        string $graphName,
        string $nodeId,
        string $status,
        float $durationMs,
        string $model,
        float $costUsd = 0,
        int $totalTokens = 0
    ): void {
        try {
            $attributes = [
                'graph.name' => $graphName,
                'graph.node_id' => $nodeId,
                'graph.node_status' => $status,
                'ai.model' => $model,
            ];

            $this->getPipelineNodeExecutionsCounter()->add(1, $attributes);
            $this->getPipelineNodeDurationHistogram()->record($durationMs, $attributes);

            if ($totalTokens > 0) {
                $this->getPipelineNodeTokensCounter()->add($totalTokens, $attributes);
            }

            if ($costUsd > 0) {
                $this->getPipelineNodeCostCounter()->add($costUsd, $attributes);
            }
        } catch (\Throwable) {
        }
    }

    private function getMeter(): mixed
    {
        if (!class_exists(Globals::class)) {
            throw new \RuntimeException('OpenTelemetry indisponivel');
        }

        return Globals::meterProvider()->getMeter('painel-laravel-app');
    }

    private function getJobExecutionsCounter(): mixed
    {
        if ($this->jobExecutionsCounter === null) {
            $this->jobExecutionsCounter = $this->getMeter()->createCounter(
                'laravel_job_executions_total',
                '{job}',
                'Total de execucoes de jobs por status'
            );
        }

        return $this->jobExecutionsCounter;
    }

    private function getJobDurationHistogram(): mixed
    {
        if ($this->jobDurationHistogram === null) {
            $this->jobDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_job_duration_ms',
                'ms',
                'Duracao de execucao de jobs em milissegundos'
            );
        }

        return $this->jobDurationHistogram;
    }

    private function getJobFailuresCounter(): mixed
    {
        if ($this->jobFailuresCounter === null) {
            $this->jobFailuresCounter = $this->getMeter()->createCounter(
                'laravel_job_failures_total',
                '{job}',
                'Total de falhas de jobs'
            );
        }

        return $this->jobFailuresCounter;
    }

    private function getDbQueryDurationHistogram(): mixed
    {
        if ($this->dbQueryDurationHistogram === null) {
            $this->dbQueryDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_db_query_duration_ms',
                'ms',
                'Duracao de queries de banco de dados'
            );
        }

        return $this->dbQueryDurationHistogram;
    }

    private function getAiApiCallsCounter(): mixed
    {
        if ($this->aiApiCallsCounter === null) {
            $this->aiApiCallsCounter = $this->getMeter()->createCounter(
                'laravel_ai_api_calls_total',
                '{call}',
                'Total de chamadas para APIs de IA'
            );
        }

        return $this->aiApiCallsCounter;
    }

    private function getAiApiDurationHistogram(): mixed
    {
        if ($this->aiApiDurationHistogram === null) {
            $this->aiApiDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_ai_api_duration_ms',
                'ms',
                'Duracao de chamadas para APIs de IA'
            );
        }

        return $this->aiApiDurationHistogram;
    }

    private function getAiTokensCounter(): mixed
    {
        if ($this->aiTokensCounter === null) {
            $this->aiTokensCounter = $this->getMeter()->createCounter(
                'laravel_ai_tokens_used_total',
                '{token}',
                'Total de tokens consumidos em chamadas de IA'
            );
        }

        return $this->aiTokensCounter;
    }

    private function getExternalApiCallsCounter(): mixed
    {
        if ($this->externalApiCallsCounter === null) {
            $this->externalApiCallsCounter = $this->getMeter()->createCounter(
                'laravel_external_api_calls_total',
                '{call}',
                'Total de chamadas para APIs externas'
            );
        }

        return $this->externalApiCallsCounter;
    }

    private function getExternalApiDurationHistogram(): mixed
    {
        if ($this->externalApiDurationHistogram === null) {
            $this->externalApiDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_external_api_duration_ms',
                'ms',
                'Duracao de chamadas para APIs externas'
            );
        }

        return $this->externalApiDurationHistogram;
    }

    private function getDocumentExtractionCounter(): mixed
    {
        if ($this->documentExtractionCounter === null) {
            $this->documentExtractionCounter = $this->getMeter()->createCounter(
                'laravel_document_extractions_total',
                '{extraction}',
                'Total de extracoes de conteudo por formato e status'
            );
        }

        return $this->documentExtractionCounter;
    }

    private function getDocumentExtractionDurationHistogram(): mixed
    {
        if ($this->documentExtractionDurationHistogram === null) {
            $this->documentExtractionDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_document_extraction_duration_ms',
                'ms',
                'Duracao de extracao de conteudo de documentos'
            );
        }

        return $this->documentExtractionDurationHistogram;
    }

    private function getDocumentProcessedCharsCounter(): mixed
    {
        if ($this->documentProcessedCharsCounter === null) {
            $this->documentProcessedCharsCounter = $this->getMeter()->createCounter(
                'laravel_document_processed_chars_total',
                '{char}',
                'Total de caracteres processados na extracao de documentos'
            );
        }

        return $this->documentProcessedCharsCounter;
    }

    private function getNotificationCounter(): mixed
    {
        if ($this->notificationCounter === null) {
            $this->notificationCounter = $this->getMeter()->createCounter(
                'laravel_notifications_total',
                '{notification}',
                'Total de notificacoes enviadas por status'
            );
        }

        return $this->notificationCounter;
    }

    private function getHttpRequestsCounter(): mixed
    {
        if ($this->httpRequestsCounter === null) {
            $this->httpRequestsCounter = $this->getMeter()->createCounter(
                'laravel_http_server_requests_total',
                '{request}',
                'Total de requisições HTTP no Laravel'
            );
        }

        return $this->httpRequestsCounter;
    }

    private function getHttpRequestDurationHistogram(): mixed
    {
        if ($this->httpRequestDurationHistogram === null) {
            $this->httpRequestDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_http_server_request_duration_ms',
                'ms',
                'Duração de requisição HTTP no Laravel'
            );
        }

        return $this->httpRequestDurationHistogram;
    }

    private function getPipelineNodeExecutionsCounter(): mixed
    {
        if ($this->pipelineNodeExecutionsCounter === null) {
            $this->pipelineNodeExecutionsCounter = $this->getMeter()->createCounter(
                'laravel_pipeline_node_executions_total',
                '{node}',
                'Total de execucoes de nodes do pipeline por status'
            );
        }

        return $this->pipelineNodeExecutionsCounter;
    }

    private function getPipelineNodeDurationHistogram(): mixed
    {
        if ($this->pipelineNodeDurationHistogram === null) {
            $this->pipelineNodeDurationHistogram = $this->getMeter()->createHistogram(
                'laravel_pipeline_node_duration_ms',
                'ms',
                'Duracao dos nodes do pipeline'
            );
        }

        return $this->pipelineNodeDurationHistogram;
    }

    private function getPipelineNodeTokensCounter(): mixed
    {
        if ($this->pipelineNodeTokensCounter === null) {
            $this->pipelineNodeTokensCounter = $this->getMeter()->createCounter(
                'laravel_pipeline_node_tokens_total',
                '{token}',
                'Total de tokens registrados por node do pipeline'
            );
        }

        return $this->pipelineNodeTokensCounter;
    }

    private function getPipelineNodeCostCounter(): mixed
    {
        if ($this->pipelineNodeCostCounter === null) {
            $this->pipelineNodeCostCounter = $this->getMeter()->createCounter(
                'laravel_pipeline_node_cost_usd_total',
                'USD',
                'Custo acumulado em USD por node do pipeline'
            );
        }

        return $this->pipelineNodeCostCounter;
    }
}
