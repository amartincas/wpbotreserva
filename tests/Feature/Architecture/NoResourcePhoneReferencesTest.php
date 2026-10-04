<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * B8 — el recurso ya no es un destinatario telefónico: los avisos del
 * negocio van al owner por el CENTRAL (B6) y la agenda la consulta el owner
 * (B7). Este test falla si alguna de las piezas eliminadas vuelve a
 * aparecer en el código de producción (app/). Las migraciones históricas
 * quedan fuera a propósito: documentan cómo llegó a existir la columna.
 */
test('ningún archivo de app/ referencia el teléfono del Resource ni las clases eliminadas', function () {
    $forbidden = [
        'contact_phone',
        'contactPhone',
        'ContactPhone',
        'ProfessionalResolver',
        'ProfessionalRecipientResolver',
        'ResolvedProfessionalRecipient',
        'SendProfessional',
    ];

    $offending = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->flatMap(function ($file) use ($forbidden) {
            $contents = File::get($file->getPathname());

            return collect($forbidden)
                ->filter(fn (string $needle) => str_contains($contents, $needle))
                ->map(fn (string $needle) => "{$file->getRelativePathname()}: {$needle}");
        })
        ->values()
        ->all();

    expect($offending)->toBe([]);
});

test('la columna resources.contact_phone ya no existe', function () {
    expect(Schema::hasColumn('resources', 'contact_phone'))->toBeFalse();
});
