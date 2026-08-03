<?php

use Illuminate\Support\Facades\Process;

test('no intenta hacer dump si la conexión activa no es mysql/mariadb', function () {
    $originalDefault = config('database.default');
    config(['database.default' => 'sqlite']);

    Process::fake();

    try {
        $this->artisan('backup:database')->assertExitCode(0);
        Process::assertNothingRan();
    } finally {
        // Restaurar antes de que RefreshDatabase intente hacer rollback
        // sobre la conexión "default" en el teardown del test.
        config(['database.default' => $originalDefault]);
    }
});

test('corre mysqldump y reporta éxito cuando la conexión es mariadb', function () {
    $this->freezeTime();

    $backupDir = storage_path('app/backups');
    @mkdir($backupDir, 0755, true);
    $database = config('database.connections.mariadb.database');
    $expectedFile = $backupDir.'/'.$database.'_'.now()->format('Y-m-d_His').'.sql.gz';

    // Process::fake no ejecuta el shell real (mysqldump|gzip) — se simula
    // el archivo que ese pipeline habría dejado, en la ruta exacta que el
    // comando espera, para poder probar la validación posterior al proceso.
    Process::fake(function () use ($expectedFile) {
        file_put_contents($expectedFile, 'fake-dump-content');

        return Process::result(exitCode: 0);
    });

    $this->artisan('backup:database')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'mysqldump'));
    expect($expectedFile)->toBeFile();

    @unlink($expectedFile);
});
