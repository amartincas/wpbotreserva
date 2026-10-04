<?php

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * B5 — reemplaza a ChannelAlreadyRegisteredException: el doble registro se
 * define por el owner, no por el Channel. MVP: un owner_phone → una
 * Organization, forzado por UNIQUE(owner_phone) en organizations.
 *
 * El guard conversacional del Router (owner que ya resuelve a una
 * Organization + arranque fresco de RegistroNegocio → RegistroNegocioBloqueado)
 * cubre el caso común; esto cubre lo que ese guard no ve: un registro ya en
 * curso cuando el owner ya tenía negocio, o dos confirmaciones del mismo
 * owner casi simultáneas. RegisterOrganizationCommand la lanza dentro de su
 * transacción, así que nada del intento llega a persistir.
 */
class OwnerAlreadyRegisteredException extends RuntimeException {}
