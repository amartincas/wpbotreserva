<?php

use App\Application\Conversations\Flows\FreeTextFieldExtractor;

test('guarda el texto tal cual, sin tocarlo', function () {
    $extractor = new FreeTextFieldExtractor;

    $result = $extractor->extract('Somos una barbería especializada en cortes clásicos.', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('Somos una barbería especializada en cortes clásicos.');
});

test('recorta espacios en los extremos', function () {
    $extractor = new FreeTextFieldExtractor;

    $result = $extractor->extract('  con espacios  ', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('con espacios');
});

test('un mensaje vacío se guarda como NULL, nunca como cadena vacía', function () {
    $extractor = new FreeTextFieldExtractor;

    $result = $extractor->extract('   ', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBeNull();
});

test('palabras de omisión ("no", "ninguno", etc.) se guardan como NULL', function ($word) {
    $extractor = new FreeTextFieldExtractor;

    $result = $extractor->extract($word, []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBeNull();
})->with(['no', 'No', 'NEL', 'nop', 'no gracias', 'ninguno', 'ninguna', 'omitir', 'paso', 'skip', 'no.']);

test('nunca falla, sea cual sea la entrada', function () {
    $extractor = new FreeTextFieldExtractor;

    $result = $extractor->extract('cualquier cosa rara $$$ 12345', []);

    expect($result->successful)->toBeTrue();
});
