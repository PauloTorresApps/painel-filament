<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'eproc' => [
        'wsdl_url' => env('URL_SOAP_WEBSERVICE'),
        'url_base' => env('URL_BASE_WEBSERVICE'),
        // Credenciais são fornecidas pelo usuário via formulário
        // e passadas diretamente para o EprocService
    ],

    'cnj' => [
        'url' => env('URL_CNJ_WEBSERVICE', 'https://www.cnj.jus.br/sgt/sgt_ws.php'),
    ],

/*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'default_provider' => 'openrouter',
        'batch_size' => env('AI_BATCH_SIZE', 10),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'api_url' => config('laravel-openrouter.api_endpoint', 'https://openrouter.ai/api/v1/'),
        'model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4'),
        'timeout' => env('OPENROUTER_TIMEOUT', 300),
        'rate_limit_per_minute' => env('OPENROUTER_RATE_LIMIT_PER_MINUTE', 30),

        // Roteamento de modelos por tipo de documento (opcional)
        // Se null, usa o modelo padrão (OPENROUTER_MODEL)
        'model_routing' => [
            'pdf_text' => env('OPENROUTER_MODEL_PDF_TEXT'),
            'pdf_ocr' => env('OPENROUTER_MODEL_PDF_OCR'),
            'vision' => env('OPENROUTER_MODEL_VISION'),
            'large_context' => env('OPENROUTER_MODEL_LARGE'),
            'default' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4'),
        ],

        // Provider routing: resiliência e performance (opcional)
        // Prioridade de providers (ex: 'anthropic,google,openai')
        'provider_order' => env('OPENROUTER_PROVIDER_ORDER'),
        // Permite fallback automático para outros providers se o primário falhar
        'allow_fallbacks' => env('OPENROUTER_ALLOW_FALLBACKS', true),
        // Ordenação de providers: 'price', 'throughput', 'latency' ou null (load balancing padrão)
        'provider_sort' => env('OPENROUTER_PROVIDER_SORT'),
        // Só roteia para providers que suportam todos os parâmetros da request
        'require_parameters' => env('OPENROUTER_REQUIRE_PARAMETERS', true),

        // Controle de custo: teto de preço por 1M tokens (hard limit)
        'max_price_prompt' => env('OPENROUTER_MAX_PRICE_PROMPT'),
        'max_price_completion' => env('OPENROUTER_MAX_PRICE_COMPLETION'),

        // Middle-out transform: comprime prompts que excedem a janela de contexto
        // 'middle-out' = habilitado, '' = desabilitado
        'transforms' => env('OPENROUTER_TRANSFORMS', 'middle-out'),

        // Structured outputs: respostas JSON na fase MAP para dados consistentes
        // Requer modelos compatíveis (GPT-4o+, Gemini, Claude Sonnet 4+)
        'structured_map_enabled' => env('OPENROUTER_STRUCTURED_MAP_ENABLED', false),

        // Web search plugin para o parecer final
        'web_search_enabled' => env('OPENROUTER_WEB_SEARCH_ENABLED', false),
        'web_search_max_results' => env('OPENROUTER_WEB_SEARCH_MAX_RESULTS', 3),
    ],

];
