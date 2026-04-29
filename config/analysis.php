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
            'tries' => (int) env('ANALYSIS_MAP_TRIES', 1),
            'backoff' => 30,
            'unique_for' => (int) env('ANALYSIS_MAP_UNIQUE_FOR', 900),
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
        'large_document_chars' => (int) env('ANALYSIS_LARGE_DOC_THRESHOLD', 250000),

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
    | MAP Cache
    |--------------------------------------------------------------------------
    |
    | Reaproveita micro-análises já concluídas para o mesmo conteúdo (hash)
    | e estratégia, reduzindo chamadas LLM em reprocessamentos.
    |
    */

    'map_cache' => [
        'enabled' => (bool) env('ANALYSIS_MAP_CACHE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reduce Configuration
    |--------------------------------------------------------------------------
    */

    'reduce' => [
        'batch_size' => (int) env('ANALYSIS_BATCH_SIZE', 10),
        'max_levels' => (int) env('ANALYSIS_MAX_REDUCE_LEVELS', 5),
        // Limite para consolidar em uma única chamada no RefineReduceJob
        'direct_consolidation_chars' => (int) env('ANALYSIS_DIRECT_CONSOLIDATION_CHARS', 800000),
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
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | verbose_job_logs: habilita logs detalhados dos jobs de pipeline.
    | Em produção, mantenha false para reduzir I/O e volume no Loki.
    |
    */

    'telemetry' => [
        'verbose_job_logs' => (bool) env('ANALYSIS_VERBOSE_JOB_LOGS', false),
    ],

];
