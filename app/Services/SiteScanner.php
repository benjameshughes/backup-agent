<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class SiteScanner
{
    protected string $sitesPath;

    public function __construct()
    {
        $this->sitesPath = config('backup.sites_path');
    }

    /**
     * Scan for Laravel sites and return database configurations.
     *
     * @return Collection<int, array{site: string, database: string, connection: array}>
     */
    public function scan(): Collection
    {
        $sites = collect();

        if (! File::isDirectory($this->sitesPath)) {
            return $sites;
        }

        $directories = File::directories($this->sitesPath);

        foreach ($directories as $directory) {
            $envPath = $directory.'/.env';

            if (! File::exists($envPath)) {
                continue;
            }

            $database = $this->parseDatabaseConfig($envPath);

            if ($database) {
                $sites->push([
                    'site' => basename($directory),
                    'database' => $database['database'],
                    'connection' => $database,
                ]);
            }
        }

        return $sites;
    }

    /**
     * Parse database configuration from .env file.
     *
     * @return array{driver: string, host: string, port: int, database: string, username: string, password: string}|null
     */
    protected function parseDatabaseConfig(string $envPath): ?array
    {
        $env = $this->parseEnvFile($envPath);

        $driver = $env['DB_CONNECTION'] ?? 'mysql';

        // Only support MySQL for now
        if ($driver !== 'mysql') {
            return null;
        }

        $database = $env['DB_DATABASE'] ?? null;

        if (! $database) {
            return null;
        }

        return [
            'driver' => $driver,
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($env['DB_PORT'] ?? 3306),
            'database' => $database,
            'username' => $env['DB_USERNAME'] ?? 'forge',
            'password' => $env['DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Parse .env file into key-value array.
     *
     * @return array<string, string>
     */
    protected function parseEnvFile(string $path): array
    {
        $content = File::get($path);
        $env = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes
                if (preg_match('/^["\'](.*)["\']+$/', $value, $matches)) {
                    $value = $matches[1];
                }

                $env[$key] = $value;
            }
        }

        return $env;
    }
}
