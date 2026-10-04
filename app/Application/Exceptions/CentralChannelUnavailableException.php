<?php

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * No hay exactamente un Channel CENTRAL ACTIVE — o no existe ninguno
 * (todavía no se promovió, o está inactivo), o hay más de uno (solo
 * alcanzable con SQL manual, la invariante de Channel lo impide vía
 * Eloquent). En ambos casos no hay un número válido por el cual hablarle
 * al owner, así que se falla en voz alta en vez de elegir uno al azar.
 */
class CentralChannelUnavailableException extends RuntimeException {}
