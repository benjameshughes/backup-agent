<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class BackupEncryptor
{
    /**
     * Generate a random encryption key.
     */
    public function generateKey(): string
    {
        return Str::random(32);
    }

    /**
     * Encrypt a file using AES-256-CBC via OpenSSL.
     *
     * @return array{path: string, checksum: string}
     */
    public function encrypt(string $inputPath, string $outputPath, string $key): array
    {
        $this->ensureDirectoryExists(dirname($outputPath));

        // Use OpenSSL to encrypt the file with AES-256-CBC
        $command = sprintf(
            'openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -pass pass:%s',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($key)
        );

        $result = Process::timeout(3600)->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to encrypt backup: '.$result->errorOutput());
        }

        // Calculate checksum of encrypted file
        $checksum = hash_file('sha256', $outputPath);

        return [
            'path' => $outputPath,
            'checksum' => $checksum,
        ];
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
