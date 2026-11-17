<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Takes a copy of everything the application cannot rebuild.
 *
 * The database and the uploaded files, in one archive, on a schedule. Not a
 * substitute for the fiscal retention the documents themselves carry — that
 * lives in the records; this is what gets you back after a bad migration, a
 * dropped table or a lost machine.
 *
 * Deliberately shells out to the database's own dump tool rather than walking
 * the tables in PHP: mysqldump knows about views, triggers and character sets,
 * and a restore that half-works is worse than no backup.
 */
#[Signature('backup:run {--keep= : Override how many archives to retain}')]
#[Description('Archives the database and stored files to the configured backup disk.')]
class BackUpApplicationData extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk((string) config('backup.disk', 'local'));
        $directory = (string) config('backup.directory', 'backups');

        $name = sprintf('%s/backup-%s.zip', $directory, now()->format('Y-m-d-His').'-'.str()->uuid());
        $workingCopy = tempnam(sys_get_temp_dir(), 'vap-backup');

        if ($workingCopy === false) {
            $this->error('Não foi possível preparar o ficheiro temporário.');

            return self::FAILURE;
        }

        try {
            $this->buildArchive($workingCopy);

            // Streamed rather than read whole: an archive of a working
            // database should not have to fit in PHP's memory limit.
            $handle = fopen($workingCopy, 'r');

            if ($handle === false) {
                throw new RuntimeException('Não foi possível ler o arquivo gerado.');
            }

            try {
                if (! $disk->writeStream($name, $handle)) {
                    throw new RuntimeException('Não foi possível guardar a cópia de segurança.');
                }
            } finally {
                fclose($handle);
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($workingCopy);
        }

        $size = $disk->size($name);
        $this->info(sprintf('Cópia de segurança criada: %s (%s).', $name, $this->humanSize($size)));

        $removed = $this->prune($disk, $directory);

        if ($removed > 0) {
            $this->line("{$removed} cópia(s) antiga(s) removida(s).");
        }

        return self::SUCCESS;
    }

    private function buildArchive(string $path): void
    {
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo.');
        }

        $temporaryFiles = [];
        try {
            $archive->addFromString('base-de-dados.sql', $this->databaseDump());
            $archive->addFromString('LEIA-ME.txt', $this->readme());
            $source = Storage::disk((string) config('filesystems.private_disk', 'local'));

            foreach ((array) config('backup.include', []) as $relative) {
                foreach ($source->allFiles((string) $relative) as $file) {
                    $temporary = tempnam(sys_get_temp_dir(), 'facturac-backup-file-');
                    if ($temporary === false) {
                        throw new RuntimeException('Não foi possível preparar o ficheiro temporário.');
                    }
                    $temporaryFiles[] = $temporary;
                    $input = $source->readStream($file);
                    if (! is_resource($input)) {
                        throw new RuntimeException('Não foi possível ler um ficheiro para a cópia de segurança.');
                    }
                    $output = fopen($temporary, 'wb');
                    if ($output === false) {
                        fclose($input);
                        throw new RuntimeException('Não foi possível preparar o ficheiro temporário.');
                    }
                    try {
                        if (stream_copy_to_stream($input, $output) === false) {
                            throw new RuntimeException('Não foi possível copiar um ficheiro para a cópia de segurança.');
                        }
                    } finally {
                        fclose($input);
                        fclose($output);
                    }
                    if (! $archive->addFile($temporary, 'ficheiros/'.$file)) {
                        throw new RuntimeException('Não foi possível adicionar um ficheiro à cópia de segurança.');
                    }
                }
            }
            if (! $archive->close()) {
                throw new RuntimeException('Não foi possível concluir a cópia de segurança.');
            }
        } finally {
            unset($archive);
            foreach ($temporaryFiles as $temporary) {
                @unlink($temporary);
            }
        }
    }

    /**
     * The database as SQL.
     *
     * SQLite is copied verbatim because the file is the database; MySQL goes
     * through mysqldump and PostgreSQL through pg_dump.
     */
    private function databaseDump(): string
    {
        $connection = $this->connectionName();
        $config = DB::connection($connection)->getConfig();

        if (($config['driver'] ?? null) === 'sqlite') {
            $database = (string) ($config['database'] ?? '');

            if ($database === ':memory:' || ! is_file($database)) {
                return '-- Base de dados em memória: nada para exportar.';
            }

            return (string) file_get_contents($database);
        }

        $driver = $config['driver'] ?? null;
        if ($driver === 'pgsql') {
            $command = [
                'pg_dump', '--no-password', '--no-owner', '--no-acl', '--format=plain',
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? 5432),
                '--username='.($config['username'] ?? ''),
                (string) ($config['database'] ?? ''),
            ];
            $environment = [
                'PGPASSWORD' => (string) ($config['password'] ?? ''),
                'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
                'PGCONNECT_TIMEOUT' => '15',
            ];
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $command = [
                'mysqldump',
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? 3306),
                '--user='.($config['username'] ?? ''),
                '--single-transaction', '--skip-lock-tables', '--routines', '--triggers',
                '--default-character-set=utf8mb4',
                (string) ($config['database'] ?? ''),
            ];
            $environment = ['MYSQL_PWD' => (string) ($config['password'] ?? '')];
        } else {
            throw new RuntimeException('O motor da base de dados não suporta cópias de segurança.');
        }

        $process = Process::timeout(600)->env($environment)->run($command);
        if (! $process->successful()) {
            throw new RuntimeException('A exportação da base de dados falhou: '.trim($process->errorOutput()));
        }

        return $process->output();
    }

    private function connectionName(): string
    {
        return (string) (config('backup.connection') ?? config('database.default'));
    }

    /**
     * Deletes the oldest archives past the retention count.
     */
    private function prune(Filesystem $disk, string $directory): int
    {
        $keep = (int) ($this->option('keep') ?? config('backup.keep', 14));

        if ($keep < 1) {
            return 0;
        }

        $files = collect($disk->files($directory))
            ->filter(fn (string $file): bool => str_ends_with($file, '.zip'))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->values();

        $stale = $files->slice($keep);

        foreach ($stale as $file) {
            $disk->delete($file);
        }

        return $stale->count();
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $size, $units[$unit]);
    }

    private function readme(): string
    {
        $when = now()->toDayDateTimeString();
        $connection = $this->connectionName();

        return <<<TXT
        Cópia de segurança — {$when}
        Ligação: {$connection}

        Para restaurar:
          1. Crie uma base de dados vazia.
          2. mysql -u UTILIZADOR -p NOME_DA_BASE < base-de-dados.sql
             (em SQLite, substitua o ficheiro .sqlite pelo base-de-dados.sql,
              que é uma cópia binária do original.)
             PostgreSQL: psql -U UTILIZADOR -d NOME_DA_BASE -f base-de-dados.sql
          3. Copie o conteúdo de ficheiros/ para o disco privado configurado.
          4. php artisan migrate --force

        Confirme sempre um restauro num ambiente separado antes de precisar
        dele a sério. Uma cópia que nunca foi restaurada não é uma cópia.
        TXT;
    }
}
