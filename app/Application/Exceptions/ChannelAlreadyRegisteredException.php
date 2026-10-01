<?php

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * Fase 6 — defensa transaccional (capa 2) contra doble registro: el Channel
 * que RegisterOrganizationCommand intentó vincular ya tiene una Organization
 * (UNIQUE(channel_id) en channel_organization). El guard conversacional del
 * Router (capa 1, InboundMessageRouter) cubre el caso común; esto cubre la
 * condición de carrera real entre dos remitentes distintos del mismo Channel
 * confirmando su registro casi al mismo tiempo — ninguno de los dos locks de
 * Redis del Job (uno por remitente, no por Channel) los serializa entre sí.
 */
class ChannelAlreadyRegisteredException extends RuntimeException {}
