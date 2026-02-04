<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DatabaseDumper
{
    /**
     * Dump a MySQL database to a file.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $connection
     * @return array{path: string, table_count: int}
     *
     * @throws ProcessFailedException
     */
    public function dump(array $connection, string $outputPath): array
    {
        $this->ensureDirectoryExists(dirname($outputPath));

        $tableCount = $this->getTableCount($connection);

        $command = $this->buildDumpCommand($connection, $outputPath);

        $result = Process::timeout(3600)->run($command);

        if (! $result->successful()) {
            throw new ProcessFailedException($result);
        }

        return [
            'path' => $outputPath,
            'table_count' => $tableCount,
        ];
    }

    /**
     * Build the mysqldump command.
     */
    protected function buildDumpCommand(array $connection, string $outputPath): string
    {
        $args = [
            'mysqldump',
            '--host='.escapeshellarg($connection['host']),
            '--port='.escapeshellarg((string) $connection['port']),
            '--user='.escapeshellarg($connection['username']),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--quick',
            '--lock-tables=false',
        ];

        if (! empty($connection['password'])) {
            $args[] = '--password='.escapeshellarg($connection['password']);
        }

        $args[] = escapeshellarg($connection['database']);
        $args[] = '>';
        $args[] = escapeshellarg($outputPath);

        return implode(' ', $args);
    }

    /**
     * Get the number of tables in the database.
     */
    protected function getTableCount(array $connection): int
    {
        $command = sprintf(
            "mysql -h %s -P %s -u %s %s -N -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s'",
            escapeshellarg($connection['host']),
            escapeshellarg((string) $connection['port']),
            escapeshellarg($connection['username']),
            ! empty($connection['password']) ? '-p'.escapeshellarg($connection['password']) : '',
            escapeshellarg($connection['database'])
        );

        $result = Process::timeout(30)->run($command);

        if ($result->successful()) {
            return (int) trim($result->output());
        }

        return 0;
    }

    /**
     * Ensure the directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }
}
