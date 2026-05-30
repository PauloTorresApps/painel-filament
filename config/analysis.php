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

        'build_inventory' => [
            'timeout' => (int) env('ANALYSIS_INVENTORY_TIMEOUT', 300),
            'tries' => 2,
            'backoff' => 30,
        ],

        'build_chronology' => [
            'timeout' => (int) env('ANALYSIS_CHRONOLOGY_TIMEOUT', 300),
            'tries' => 2,
            'backoff' => 30,
        ],

        'run_engine' => [
            'timeout' => (int) env('ANALYSIS_ENGINE_TIMEOUT', 600),
            'tries' => 2,
            'backoff' => 60,
        ],

        'build_structured_parecer' => [
            'timeout' => (int) env('ANALYSIS_PARECER_STRUCTURED_TIMEOUT', 600),
            'tries' => 2,
            'backoff' => 60,
        ],

        'build_designer_brief' => [
            'timeout' => (int) env('ANALYSIS_DESIGNER_TIMEOUT', 180),
            'tries' => 2,
            'backoff' => 30,
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
    | Eproc Integration
    |--------------------------------------------------------------------------
    |
    | Cache de resposta para consultas de documentos do webservice eproc.
    | Reduz chamadas SOAP repetidas em reanálises do mesmo processo.
    |
    */

    'eproc' => [
        'documents_cache_enabled' => (bool) env('ANALYSIS_EPROC_DOCUMENTS_CACHE_ENABLED', true),
        'documents_cache_ttl_minutes' => (int) env('ANALYSIS_EPROC_DOCUMENTS_CACHE_TTL_MINUTES', 1440),
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
    | Graph Runner (LangGraph-like abstraction)
    |--------------------------------------------------------------------------
    |
    | Fase 0 spike: mantém o pipeline atual e habilita somente o roteamento
    | inicial de InventoryNode atrás de feature flag.
    |
    */

    'graph_runner' => [
        'enabled' => (bool) env('ANALYSIS_GRAPH_RUNNER_ENABLED', false),
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
        'direct_consolidation_chars' => (int) env('ANALYSIS_DIRECT_CONSOLIDATION_CHARS', 2000000),
        // Overrides por trecho do model_id (match por contains, case-insensitive).
        // Ex.: model_id "google/gemini-2.5-pro" casa com "gemini".
        'direct_consolidation_chars_overrides' => [
            'gemini' => (int) env('ANALYSIS_DIRECT_CONSOLIDATION_CHARS_GEMINI', 3500000),
            'claude' => (int) env('ANALYSIS_DIRECT_CONSOLIDATION_CHARS_CLAUDE', 2000000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OWLEX Configuration
    |--------------------------------------------------------------------------
    */

    'owlex' => [
        'enabled' => (bool) env('OWLEX_PIPELINE_ENABLED', false),

        'inventory' => [
            'duplicate_hash_algorithm' => env('ANALYSIS_INVENTORY_HASH_ALGO', 'sha256'),
            'min_text_chars_legible' => (int) env('ANALYSIS_INVENTORY_MIN_LEGIBLE_CHARS', 120),
        ],

        'chronology' => [
            'inertia_gap_days' => (int) env('ANALYSIS_CHRONOLOGY_INERTIA_GAP_DAYS', 60),
        ],

        'engine' => [
            'score' => [
                'critical_min' => (int) env('ANALYSIS_SCORE_CRITICAL_MIN', 80),
                'high_min' => (int) env('ANALYSIS_SCORE_HIGH_MIN', 60),
                'medium_min' => (int) env('ANALYSIS_SCORE_MEDIUM_MIN', 40),
            ],
        ],
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
