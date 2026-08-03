<?php

use App\Domain\Booking\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

test('rechaza construirse con end anterior o igual a start', function () {
    $t = CarbonImmutable::parse('2026-09-01 10:00');

    expect(fn () => new TimeRange($t, $t))->toThrow(InvalidArgumentException::class);
    expect(fn () => new TimeRange($t, $t->subMinute()))->toThrow(InvalidArgumentException::class);
});

test('durationInMinutes calcula correctamente', function () {
    $range = new TimeRange(CarbonImmutable::parse('2026-09-01 10:00'), CarbonImmutable::parse('2026-09-01 10:30'));
    expect($range->durationInMinutes())->toBe(30);
});

test('overlaps detecta solapamiento y no-solapamiento', function () {
    $a = new TimeRange(CarbonImmutable::parse('10:00'), CarbonImmutable::parse('11:00'));
    $b = new TimeRange(CarbonImmutable::parse('10:30'), CarbonImmutable::parse('11:30'));
    $c = new TimeRange(CarbonImmutable::parse('11:00'), CarbonImmutable::parse('12:00'));
    $d = new TimeRange(CarbonImmutable::parse('12:00'), CarbonImmutable::parse('13:00'));

    expect($a->overlaps($b))->toBeTrue();
    expect($a->overlaps($c))->toBeFalse(); // contiguos, no se solapan (end == start)
    expect($a->overlaps($d))->toBeFalse();
});

test('contains detecta si un rango está completamente dentro de otro', function () {
    $outer = new TimeRange(CarbonImmutable::parse('09:00'), CarbonImmutable::parse('18:00'));
    $inner = new TimeRange(CarbonImmutable::parse('10:00'), CarbonImmutable::parse('11:00'));
    $partial = new TimeRange(CarbonImmutable::parse('17:30'), CarbonImmutable::parse('18:30'));

    expect($outer->contains($inner))->toBeTrue();
    expect($outer->contains($partial))->toBeFalse();
});

test('withTrailingBuffer extiende el final sin modificar el original (inmutabilidad)', function () {
    $range = new TimeRange(CarbonImmutable::parse('10:00'), CarbonImmutable::parse('10:30'));
    $extended = $range->withTrailingBuffer(15);

    expect($extended->end->format('H:i'))->toBe('10:45');
    expect($range->end->format('H:i'))->toBe('10:30'); // el original no cambia
});

test('equals compara por valor', function () {
    $a = new TimeRange(CarbonImmutable::parse('2026-09-01 10:00'), CarbonImmutable::parse('2026-09-01 10:30'));
    $b = new TimeRange(CarbonImmutable::parse('2026-09-01 10:00'), CarbonImmutable::parse('2026-09-01 10:30'));
    $c = new TimeRange(CarbonImmutable::parse('2026-09-01 11:00'), CarbonImmutable::parse('2026-09-01 11:30'));

    expect($a->equals($b))->toBeTrue();
    expect($a->equals($c))->toBeFalse();
});
