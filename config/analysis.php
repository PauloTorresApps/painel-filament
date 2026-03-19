<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Job Timeouts and Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Timeout em segundos, backoff em segundos, tries = tentativas máximas.
    | Valores centralizados de todos os jobs do pipeline Map-Reduce.
    |
    */

    'jobs' => [
        'analyze_process' => [
            'timeout' => (int) env('ANALYSIS_PROCESS_TIMEOUT', 1800),
            'tries' => 2,
            'unique_for' => 600,
        ],

        'dispatch_map' => [
            'timeout' => 120,
            'tries' => 3,
        ],

        'map_document' => [
            'timeout' => (int) env('ANALYSIS_MAP_TIMEOUT', 300),
            'tries' => 3,
            'backoff' => 30,
        ],

        'chunk_large_document' => [
            'timeout' => (int) env('ANALYSIS_CHUNK_TIMEOUT', 3600),
            'tries' => 2,
            'backoff' => 120,
        ],

        'reduce_document' => [
            'timeout' => (int) env('ANALYSIS_REDUCE_TIMEOUT', 600),
            'tries' => 3,
            'backoff' => 60,
        ],

        'reduce_batch' => [
            'timeout' => 600,
            'tries' => 3,
            'backoff' => 60,
        ],

        'check_reduce_completion' => [
            'timeout' => 600,
            'tries' => 3,
            'backoff' => 30,
        ],

        'refine_reduce' => [
            'timeout' => 1800,
            'tries' => 2,
            'backoff' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        // Documentos acima deste limite são divididos em chunks (ChunkLargeDocumentJob)
        'large_document_chars' => (int) env('ANALYSIS_LARGE_DOC_THRESHOLD', 100000),

        // Até este número de documentos, usa RefineReduceJob; acima, usa ReduceDocumentAnalysisJob
        'refine_max_documents' => (int) env('ANALYSIS_REFINE_THRESHOLD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    */

    'chunking' => [
        'chunk_size_chars' => (int) env('ANALYSIS_CHUNK_SIZE', 50000),
        'min_chunk_size' => (int) env('ANALYSIS_MIN_CHUNK_SIZE', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reduce Configuration
    |--------------------------------------------------------------------------
    */

    'reduce' => [
        'batch_size' => (int) env('ANALYSIS_BATCH_SIZE', 10),
        'max_levels' => (int) env('ANALYSIS_MAX_REDUCE_LEVELS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Se a taxa de falha de um batch exceder o threshold, o pipeline é abortado
    | para evitar pareceres finais incompletos/enganosos.
    | min_jobs: mínimo de jobs para ativar o circuit breaker (evita falsos positivos).
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (float) env('ANALYSIS_FAILURE_THRESHOLD', 0.25),
        'min_jobs' => (int) env('ANALYSIS_MIN_JOBS_CB', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Estimation
    |--------------------------------------------------------------------------
    |
    | Usado apenas para estimativas pré-envio. Não é preciso para billing.
    | Métodos: 'word_count' (palavras * multiplicador) ou 'char_divide' (chars / 4)
    |
    */

    'token_estimation' => [
        'method' => env('ANALYSIS_TOKEN_METHOD', 'word_count'),
        'word_multiplier' => (float) env('ANALYSIS_TOKEN_MULTIPLIER', 1.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Benchmark Snapshot & SLO
    |--------------------------------------------------------------------------
    |
    | Limites de SLO para monitoramento contínuo do pipeline.
    | Valor <= 0 desabilita a verificação da métrica.
    |
    */

    'benchmark' => [
        'default_window_days' => (int) env('ANALYSIS_BENCHMARK_WINDOW_DAYS', 7),
        'retention_days' => (int) env('ANALYSIS_BENCHMARK_RETENTION_DAYS', 90),
    ],

    'slo' => [
        'alert_webhook_url' => env('ANALYSIS_SLO_ALERT_WEBHOOK_URL'),
        'judicial' => [
            'p95_total_ms_max' => (int) env('ANALYSIS_SLO_JUDICIAL_P95_MS_MAX', 0),
            'docs_per_min_min' => (float) env('ANALYSIS_SLO_JUDICIAL_DOCS_PER_MIN_MIN', 0),
        ],
        'contracts' => [
            'avg_analysis_ms_max' => (int) env('ANALYSIS_SLO_CONTRACT_AVG_ANALYSIS_MS_MAX', 0),
            'avg_legal_opinion_ms_max' => (int) env('ANALYSIS_SLO_CONTRACT_AVG_LEGAL_MS_MAX', 0),
            'avg_infographic_ms_max' => (int) env('ANALYSIS_SLO_CONTRACT_AVG_INFOGRAPHIC_MS_MAX', 0),
        ],
    ],

];
