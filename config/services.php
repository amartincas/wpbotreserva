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

    /*
    |--------------------------------------------------------------------------
    | Webhook de WhatsApp (WpbotReserva, Hito 7)
    |--------------------------------------------------------------------------
    |
    | Propio y separado del webhook del bot de turismo (api/whatsapp/webhook,
    | App\Http\Controllers\WhatsAppController) — número/App de Meta distinto,
    | verify_token propio. app_secret habilita la verificación de firma
    | (X-Hub-Signature-256); sin configurar, InboundWhatsAppWebhookController
    | no puede validarla — gap explícito documentado en el controller, no
    | una omisión silenciosa (mismo estado que el webhook legado hoy, que
    | tampoco la implementa).
    |
    */

    'whatsapp_webhook' => [
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
