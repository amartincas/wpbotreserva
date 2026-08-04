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

];
