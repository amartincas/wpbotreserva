<?php

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * B9 — los datos para conectar el WhatsApp propio de un negocio no se
 * pueden aceptar: faltan credenciales, el número no es E.164, el
 * phone_number_id ya pertenece a otro Channel (incluido el CENTRAL), o el
 * Channel no está en condiciones de verificarse. El mensaje es apto para
 * mostrarse al super-admin — nunca incluye el access token.
 */
class BusinessChannelConnectionException extends RuntimeException {}
