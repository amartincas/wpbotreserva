<?php

use App\Domain\Booking\ValueObjects\CalendarDayRange;
use Carbon\CarbonImmutable;

test('start es el inicio del día, end es el inicio del día siguiente, ambos normalizados a app.timezone', function () {
    $date = CarbonImmutable::parse('2026-10-15 14:30:00', 'America/Bogota');

    $range = CalendarDayRange::forDate($date);

    expect($range->start->timezoneName)->toBe(config('app.timezone'));
    expect($range->end->timezoneName)->toBe(config('app.timezone'));
    expect($range->start->equalTo(CarbonImmutable::parse('2026-10-15 00:00:00', 'America/Bogota')))->toBeTrue();
    expect($range->end->equalTo(CarbonImmutable::parse('2026-10-16 00:00:00', 'America/Bogota')))->toBeTrue();
});

test('end es exclusivo: la diferencia entre start y end es exactamente 24 horas', function () {
    $date = CarbonImmutable::parse('2026-10-15 00:00:00', 'Asia/Tokyo');

    $range = CalendarDayRange::forDate($date);

    expect((int) $range->start->diffInHours($range->end))->toBe(24);
});

test('con timezone Asia/Tokyo, el rango representa el día local de Tokio, no el del servidor', function () {
    $date = CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Tokyo');

    $range = CalendarDayRange::forDate($date);

    // Medianoche de Tokio del 15/10 es, en America/Bogota, la tarde del 14/10.
    expect($range->start->setTimezone('Asia/Tokyo')->toDateString())->toBe('2026-10-15');
    expect($range->start->toDateString())->not->toBe('2026-10-15');
});

test('con timezone America/Los_Angeles', function () {
    $date = CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Los_Angeles');

    $range = CalendarDayRange::forDate($date);

    expect($range->start->setTimezone('America/Los_Angeles')->toDateString())->toBe('2026-03-01');
    expect($range->end->setTimezone('America/Los_Angeles')->toDateString())->toBe('2026-03-02');
});

test('borde obligatorio: una reserva exactamente a las 00:00:00 del día siguiente NO pertenece al día anterior y SÍ al siguiente', function () {
    $day = CarbonImmutable::parse('2026-10-15 00:00:00', 'America/Bogota');
    $midnightNextDay = CarbonImmutable::parse('2026-10-16 00:00:00', 'America/Bogota');

    $previousDayRange = CalendarDayRange::forDate($day);
    $nextDayRange = CalendarDayRange::forDate($day->addDay());

    // Fuera del rango del día anterior: end es exclusivo.
    expect($midnightNextDay->greaterThanOrEqualTo($previousDayRange->end))->toBeTrue();
    // Dentro del rango del día siguiente: start es inclusivo.
    expect($midnightNextDay->greaterThanOrEqualTo($nextDayRange->start))->toBeTrue();
    expect($midnightNextDay->lessThan($nextDayRange->end))->toBeTrue();
});
