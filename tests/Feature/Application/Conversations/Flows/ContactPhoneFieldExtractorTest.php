<?php

use App\Application\Conversations\Flows\ContactPhoneFieldExtractor;

// --- Formatos reconocidos, normalizados siempre a E.164 ---

test('reconoce y normaliza los formatos mínimos pedidos', function ($input, $expected) {
    $extractor = new ContactPhoneFieldExtractor;

    $result = $extractor->extract($input, []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe($expected);
})->with([
    ['+573153334455', '+573153334455'],
    ['573153334455', '+573153334455'],
    ['3153334455', '+573153334455'],
    ['+57 315 333 4455', '+573153334455'],
    ['315 333 4455', '+573153334455'],
    ['(315) 333-4455', '+573153334455'],
    ['315-333-4455', '+573153334455'],
]);

// --- Obligatorio: "no" y equivalentes NUNCA producen éxito ---

test('un rechazo explícito ("no" y equivalentes) es un fallo, nunca éxito con NULL', function ($input) {
    $extractor = new ContactPhoneFieldExtractor;

    $result = $extractor->extract($input, ['_pendingNewResourceName' => 'Carlos']);

    expect($result->successful)->toBeFalse();
    expect($result->reason)->toContain('Carlos');
    expect($result->reason)->toContain('Necesitamos');
})->with(['no', 'No', 'no.', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna']);

// --- Inválido / ambiguo: fallo, nunca se adivina ---

test('texto inválido o ambiguo es un fallo con mensaje explicativo', function ($input) {
    $extractor = new ContactPhoneFieldExtractor;

    $result = $extractor->extract($input, ['_pendingNewResourceName' => 'Carlos']);

    expect($result->successful)->toBeFalse();
    expect($result->reason)->toContain('número');
})->with([
    'no sé',
    'asdkjhasd',
    '123',
    '12345678901234567890',
    '+1',
    'mi número es el de siempre',
]);

test('un número con código de país distinto de 57 se acepta si ya viene en formato E.164 explícito', function () {
    $extractor = new ContactPhoneFieldExtractor;

    $result = $extractor->extract('+15551234567', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('+15551234567');
});
