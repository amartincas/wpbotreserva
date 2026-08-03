<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Dump + gzip de MariaDB, listo para que un proceso externo lo sincronice a
 * almacenamiento externo (Parte XV) — no decide a dónde, solo lo deja pronto.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Genera un dump comprimido y con fecha de la base de datos, con retención simple';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->warn("backup:database solo soporta mysql/mariadb, conexión actual: {$connection}. Nada que hacer.");

            return self::SUCCESS;
        }

        $directory = storage_path('app/backups');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = sprintf('%s_%s.sql.gz', $config['database'], now()->format('Y-m-d_His'));
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $dumpCommand = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --single-transaction --quick %s | gzip > %s',
            escapeshellarg($config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            escapeshellarg($path)
        );

        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => $config['password']])
            ->run($dumpCommand);

        if (! $result->successful() || ! file_exists($path) || filesize($path) === 0) {
            Log::error('BACKUP_FAILED: dump de base de datos falló', [
                'error' => $result->errorOutput(),
            ]);
            $this->error('El backup falló — ver logs.');

            return self::FAILURE;
        }

        $this->info("Backup creado: {$filename}");
        Log::info('BACKUP_CREATED', ['file' => $filename, 'size_bytes' => filesize($path)]);

        $this->pruneOldBackups($directory);

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $directory): void
    {
        $retentionDays = (int) env('BACKUP_RETENTION_DAYS', 14);
        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
