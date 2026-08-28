<?php

use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Models\BotMessage;

test('all() refleja los defaults sembrados por la migración', function () {
    $repository = new BotMessageRepository;

    $all = $repository->all();

    expect($all)->toHaveKey('registro.nombre_negocio');
    expect($all['registro.nombre_negocio'])->toBe('¿Cuál es el nombre de tu negocio?');
});

test('render() interpola placeholders en el template', function () {
    $repository = new BotMessageRepository;

    $text = $repository->render('registro.confirmar_nombre', ['nombre' => 'Impulzar']);

    expect($text)->toBe('Tu negocio se llama *Impulzar*, ¿verdad?');
});

test('render() sin placeholders devuelve el template tal cual', function () {
    $repository = new BotMessageRepository;

    expect($repository->render('registro.pedir_servicio'))
        ->toBe('¿Qué servicio ofrecés? (contame uno a la vez)');
});

test('render() devuelve null para una clave que no existe', function () {
    $repository = new BotMessageRepository;

    expect($repository->render('esta.clave_no_existe'))->toBeNull();
});

test('editar un BotMessage invalida la caché: el siguiente render() ve el valor nuevo sin reiniciar nada', function () {
    $repository = new BotMessageRepository;

    // Primera lectura, puebla la caché con el default sembrado.
    expect($repository->render('registro.nombre_negocio'))->toBe('¿Cuál es el nombre de tu negocio?');

    BotMessage::where('key', 'registro.nombre_negocio')->firstOrFail()
        ->update(['template' => '¿Cómo se llama tu negocio?']);

    // Misma instancia de repositorio, ninguna limpieza manual de caché —
    // BotMessage::booted() ya invalidó la entrada al guardar.
    expect($repository->render('registro.nombre_negocio'))->toBe('¿Cómo se llama tu negocio?');
});

test('borrar un BotMessage también invalida la caché', function () {
    $repository = new BotMessageRepository;
    expect($repository->render('registro.pedir_servicio'))->not->toBeNull();

    BotMessage::where('key', 'registro.pedir_servicio')->firstOrFail()->delete();

    expect($repository->render('registro.pedir_servicio'))->toBeNull();
});
