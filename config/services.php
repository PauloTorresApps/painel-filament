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
        'timeout' => env('OPENROUTER_TIMEOUT', 300),
        'rate_limit_per_minute' => env('OPENROUTER_RATE_LIMIT_PER_MINUTE', 30),

        // Limites de tokens de saída (completion)
        'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 8192),
        'max_tokens_reasoning' => (int) env('OPENROUTER_MAX_TOKENS_REASONING', 32768),

        // Limites de tokens por fase (REDUCE e FINAL precisam de mais espaço)
        'max_tokens_reduce' => (int) env('OPENROUTER_MAX_TOKENS_REDUCE', 16384),
        'max_tokens_final' => (int) env('OPENROUTER_MAX_TOKENS_FINAL', 65000),

        // Provider routing: resiliência e performance (opcional)
        // Prioridade de providers (ex: 'anthropic,google,openai')
        'provider_order' => env('OPENROUTER_PROVIDER_ORDER'),
        // Permite fallback automático para outros providers se o primário falhar
        'allow_fallbacks' => env('OPENROUTER_ALLOW_FALLBACKS', true),
        // Ordenação de providers: 'price', 'throughput', 'latency' ou null (load balancing padrão)
        'provider_sort' => env('OPENROUTER_PROVIDER_SORT'),
        // Só roteia para providers que suportam todos os parâmetros da request
        // Default false: providers ignoram parâmetros não suportados (ex: cache_control)
        // Com true, pode causar 404 "No endpoints found" para modelos não-Anthropic
        'require_parameters' => env('OPENROUTER_REQUIRE_PARAMETERS', false),

        // Controle de custo: teto de preço por 1M tokens (hard limit)
        'max_price_prompt' => env('OPENROUTER_MAX_PRICE_PROMPT'),
        'max_price_completion' => env('OPENROUTER_MAX_PRICE_COMPLETION'),

        // Middle-out transform: comprime prompts que excedem a janela de contexto
        // 'middle-out' = habilitado, '' = desabilitado
        'transforms' => env('OPENROUTER_TRANSFORMS', 'middle-out'),

        // Structured outputs: respostas JSON na fase MAP para dados consistentes
        // Requer modelos compatíveis com JSON structured output
        'structured_map_enabled' => env('OPENROUTER_STRUCTURED_MAP_ENABLED', false),
        // Se false, falha de structured output não faz segunda chamada em texto livre.
        // Reduz chamadas, mas pode aumentar falhas de MAP quando o modelo não respeita schema.
        'structured_map_fallback_to_text' => env('OPENROUTER_STRUCTURED_MAP_FALLBACK_TO_TEXT', false),
        // Quando habilitado fallback para texto livre, evita repetir tentativas structured
        // para todos os documentos do mesmo processo após a primeira falha.
        'structured_map_failure_cooldown_minutes' => (int) env('OPENROUTER_STRUCTURED_MAP_FAILURE_COOLDOWN_MINUTES', 30),

        // Web search plugin para o parecer final
        'web_search_enabled' => env('OPENROUTER_WEB_SEARCH_ENABLED', false),
        'web_search_max_results' => env('OPENROUTER_WEB_SEARCH_MAX_RESULTS', 3),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.3),
        'temperature_final' => (float) env('OPENROUTER_TEMPERATURE_FINAL', 0.4),
        'top_p' => env('OPENROUTER_TOP_P', 0.2),
    ],

    /*
    |--------------------------------------------------------------------------
    | wkhtmltopdf Configuration
    |--------------------------------------------------------------------------
    |
    | Usado para converter HTML do e-Proc em PDF antes da análise.
    |
    */

    'wkhtmltopdf' => [
        'binary' => env('WKHTMLTOPDF_BINARY', '/usr/bin/wkhtmltopdf'),
        'timeout' => env('WKHTMLTOPDF_TIMEOUT', 30),
    ],

];
