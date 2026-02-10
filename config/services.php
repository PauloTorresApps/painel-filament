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
    ],

];
