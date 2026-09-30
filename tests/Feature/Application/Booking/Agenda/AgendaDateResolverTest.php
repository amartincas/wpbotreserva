<?php

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Domain\Tenancy\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Timezone del servidor/app deliberadamente distinto al de la
    // Organization usada en los tests de "hoy"/"mañana" — si el resolver
    // usara now() a secas (timezone del servidor) en vez de
    // Organization.timezone, esos tests fallarían.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 23:30:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('"hoy" usa Organization.timezone, no el timezone del servidor', function () {
    // A las 23:30 UTC del 15/10, en America/Bogota (UTC-5) todavía es
    // 15/10 18:30 — "hoy" debe resolver al 15/10, no al 16/10.
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'America/Bogota']);

    $resolution = (new AgendaDateResolver)->resolve('hoy', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->unrecognized)->toBeFalse();
    expect($resolution->date->toDateString())->toBe('2026-10-15');
});

test('"hoy" en un timezone adelantado respecto al servidor puede caer al día siguiente', function () {
    // A las 23:30 UTC del 15/10, en Asia/Tokyo (UTC+9) ya es 16/10 08:30.
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'Asia/Tokyo']);

    $resolution = (new AgendaDateResolver)->resolve('hoy', $organization);

    expect($resolution->date->toDateString())->toBe('2026-10-16');
});

test('"mañana" es hoy+1 en el timezone de la Organization', function () {
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'America/Bogota']);

    $resolution = (new AgendaDateResolver)->resolve('¿Qué tengo mañana?', $organization);

    expect($resolution->date->toDateString())->toBe('2026-10-16');
});

test('dd/mm/aaaa válido resuelve success', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('22/08/2026', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->toDateString())->toBe('2026-08-22');
});

test('31/02/2026 (día inexistente para febrero) resuelve invalidCalendarDate', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('31/02/2026', $organization);

    expect($resolution->invalidCalendarDate)->toBeTrue();
    expect($resolution->unrecognized)->toBeFalse();
    expect($resolution->date)->toBeNull();
});

test('15/13/2026 (mes inexistente) resuelve invalidCalendarDate', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('15/13/2026', $organization);

    expect($resolution->invalidCalendarDate)->toBeTrue();
});

test('fecha numérica explícita en el pasado resuelve success (consulta histórica válida)', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('03/09/2020', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->toDateString())->toBe('2020-09-03');
});

test('"31 de octubre" (todavía no pasó este año) resuelve al año actual', function () {
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'America/Bogota']);

    $resolution = (new AgendaDateResolver)->resolve('31 de octubre', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->toDateString())->toBe('2026-10-31');
});

test('"3 de septiembre" (ya pasó este año) resuelve al año siguiente', function () {
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'America/Bogota']);

    $resolution = (new AgendaDateResolver)->resolve('3 de septiembre', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->toDateString())->toBe('2027-09-03');
});

test('"31 de febrero" (no existe en ningún año) resuelve invalidCalendarDate', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('31 de febrero', $organization);

    expect($resolution->invalidCalendarDate)->toBeTrue();
});

/**
 * Corrección AgendaDateResolver/timezone: resolveNumericDate() construía
 * createFromDate() sin Organization.timezone — el resultado quedaba
 * etiquetado con config('app.timezone') en vez del de la Organization. Se
 * verifica timezoneName directamente, no solo la fecha en texto (que no
 * puede detectar este defecto, ver auditoría).
 */
test('dd/mm/aaaa con Organization.timezone no-default queda etiquetado con ese timezone', function () {
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'Asia/Tokyo']);

    $resolution = (new AgendaDateResolver)->resolve('22/08/2026', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->timezoneName)->toBe('Asia/Tokyo');
    expect($resolution->date->timezoneName)->not->toBe(config('app.timezone'));
});

/**
 * Mismo defecto, mismo motivo, en resolveNamedMonthDate() ("D de mes").
 */
test('"D de mes" con Organization.timezone no-default queda etiquetado con ese timezone', function () {
    $organization = Organization::create(['name' => 'Negocio', 'timezone' => 'Asia/Tokyo']);

    $resolution = (new AgendaDateResolver)->resolve('31 de octubre', $organization);

    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date->timezoneName)->toBe('Asia/Tokyo');
    expect($resolution->date->timezoneName)->not->toBe(config('app.timezone'));
});

test('texto sin ninguna forma de fecha reconocible resuelve unrecognized', function () {
    $organization = Organization::create(['name' => 'Negocio']);

    $resolution = (new AgendaDateResolver)->resolve('hola, qué tal', $organization);

    expect($resolution->unrecognized)->toBeTrue();
    expect($resolution->invalidCalendarDate)->toBeFalse();
    expect($resolution->date)->toBeNull();
});
