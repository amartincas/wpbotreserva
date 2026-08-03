<?php

use Illuminate\Support\Facades\Process;

test('no intenta hacer dump si la conexión activa no es mysql/mariadb', function () {
    config(['database.default' => 'sqlite']);

    Process::fake();

    $this->artisan('backup:database')->assertExitCode(0);

    Process::assertNothingRan();
});

test('corre mysqldump y reporta éxito cuando la conexión es mariadb', function () {
    $this->freezeTime();

    config([
        'database.default' => 'mariadb',
        'database.connections.mariadb' => [
            'driver' => 'mariadb',
            'host' => 'mariadb',
            'port' => 3306,
            'database' => 'wpbotreserva_test',
            'username' => 'wpbotreserva',
            'password' => 'secret',
        ],
    ]);

    $backupDir = storage_path('app/backups');
    @mkdir($backupDir, 0755, true);
    $expectedFile = $backupDir.'/wpbotreserva_test_'.now()->format('Y-m-d_His').'.sql.gz';

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
