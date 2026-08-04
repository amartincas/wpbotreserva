<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deduplicación de mensajes entrantes (Hito 4, enmienda)
    |--------------------------------------------------------------------------
    |
    | Ventana durante la cual dos mensajes con el mismo message_id (WAMID de
    | Meta) se consideran el mismo evento — cubre reintentos de entrega del
    | webhook, no reintentos de negocio. 48h es un valor conservador de
    | partida, no un número verificado contra la documentación vigente de
    | Meta — ajustar si se confirma una ventana de reintento distinta.
    |
    */

    'message_dedup_hours' => env('CONVERSATION_MESSAGE_DEDUP_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Continuidad conversacional y vencimiento de flujos (Hito 5)
    |--------------------------------------------------------------------------
    |
    | Ventana de inactividad tras la cual una conversación a mitad de un
    | flujo (current_intent seteado, draft en Cache) se considera abandonada
    | — ConversationContinuityStrategy deja de honrar el Intent activo y
    | ConversationDraftRepository deja de servir el draft, dejando que la IA
    | clasifique el próximo mensaje de cero. 4h es un valor conservador de
    | partida (preferido explícitamente sobre 30min), a ajustar con datos
    | reales de uso — nunca se afirmó como definitivo. Mismo valor para
    | sesión y draft a propósito: evita que uno expire y el otro no.
    |
    */

    'continuity_ttl_minutes' => env('CONVERSATION_CONTINUITY_TTL_MINUTES', 240),

];
