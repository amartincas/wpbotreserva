<?php

use App\Application\Conversations\Flows\ServicePriceFieldExtractor;

test('reconoce un número plano como precio', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('45000', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->price)->toBe(45000.0);
    expect($result->value->priceCondition)->toBeNull();
});

test('reconoce un número con separador de miles (punto)', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('45.000', []);

    expect($result->value->price)->toBe(45000.0);
    expect($result->value->priceCondition)->toBeNull();
});

test('reconoce un número con separador de miles (coma)', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('$45,000', []);

    expect($result->value->price)->toBe(45000.0);
});

test('reconoce un número con símbolo de peso y espacio', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('$ 45.000', []);

    expect($result->value->price)->toBe(45000.0);
});

test('"45 mil" NO se convierte a un número — no es un patrón numérico reconocido', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('45 mil', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->price)->toBeNull();
    expect($result->value->priceCondition)->toBe('45 mil');
});

test('una condición en texto libre no se convierte a precio, se preserva tal cual', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('depende de la valoración', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->price)->toBeNull();
    expect($result->value->priceCondition)->toBe('depende de la valoración');
});

test('otra condición en texto libre: "se define después de una consulta"', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('se define después de una consulta', []);

    expect($result->value->price)->toBeNull();
    expect($result->value->priceCondition)->toBe('se define después de una consulta');
});

test('una respuesta vacía o de rechazo ("no") no inventa ni precio ni condición', function ($word) {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract($word, []);

    expect($result->successful)->toBeTrue();
    expect($result->value->price)->toBeNull();
    expect($result->value->priceCondition)->toBeNull();
})->with(['', '   ', 'no', 'No', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna']);

test('nunca falla, sea cual sea la entrada', function () {
    $extractor = new ServicePriceFieldExtractor;

    $result = $extractor->extract('¿?¿?¿?', []);

    expect($result->successful)->toBeTrue();
});
