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

    /*
    |--------------------------------------------------------------------------
    | Intent Classifier (WpbotReserva, Hito 4)
    |--------------------------------------------------------------------------
    |
    | Credenciales de IA propias de la plataforma para clasificar mensajes
    | entrantes (AiIntentClassifierStrategy) — deliberadamente distintas de
    | las credenciales de IA que cada Store del bot de turismo trae por su
    | cuenta (ai_provider/ai_api_key en stores). WpbotReserva clasifica con
    | su propia cuenta, nunca con la del negocio.
    |
    */

    'intent_classifier' => [
        'provider' => env('INTENT_CLASSIFIER_AI_PROVIDER', 'openai'),
        'model' => env('INTENT_CLASSIFIER_AI_MODEL', 'gpt-4o-mini'),
        'key' => env('INTENT_CLASSIFIER_AI_API_KEY'),
    ],

];
