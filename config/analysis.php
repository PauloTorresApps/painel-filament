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

];
