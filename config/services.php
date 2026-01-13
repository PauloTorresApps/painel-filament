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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'rate_limit_per_minute' => env('GEMINI_RATE_LIMIT_PER_MINUTE', 15),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'api_url' => env('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'rate_limit_per_minute' => env('DEEPSEEK_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'rate_limit_per_minute' => env('OPENAI_RATE_LIMIT_PER_MINUTE', 3),
    ],

];
