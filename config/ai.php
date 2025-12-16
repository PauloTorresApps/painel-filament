<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações para os provedores de IA (Gemini e DeepSeek)
    |
    */

    'default_provider' => env('AI_DEFAULT_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Document Analysis Batch Size
    |--------------------------------------------------------------------------
    |
    | Número de documentos processados por lote na análise.
    | Valores menores evitam erro 413 (payload muito grande), mas aumentam
    | o número de requisições à API.
    |
    | Recomendado:
    | - 5-10 para documentos grandes (>100KB)
    | - 15-20 para documentos médios (50-100KB)
    | - 30+ para documentos pequenos (<50KB)
    |
    */

    'batch_size' => env('AI_BATCH_SIZE', 10),

    /*
    |--------------------------------------------------------------------------
    | Gemini Configuration
    |--------------------------------------------------------------------------
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DeepSeek Configuration
    |--------------------------------------------------------------------------
    */

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'api_url' => env('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    ],

];
