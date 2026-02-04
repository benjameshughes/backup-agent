<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PanelApi
{
    protected string $baseUrl;

    protected ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('backup.panel_url'), '/');
        $this->token = config('backup.api_token');
    }

    protected function client(): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->acceptJson();

        if ($this->token) {
            $client->withToken($this->token);
        }

        return $client;
    }

    public function register(string $name, string $publicKey, ?string $ipAddress = null): Response
    {
        return $this->client()->post('/api/servers/register', [
            'name' => $name,
            'public_key' => $publicKey,
            'ip_address' => $ipAddress,
        ]);
    }

    public function checkStatus(string $fingerprint): Response
    {
        return $this->client()->get('/api/servers/status', [
            'fingerprint' => $fingerprint,
        ]);
    }

    public function verify(string $fingerprint, string $signedChallenge): Response
    {
        return $this->client()->post('/api/servers/verify', [
            'fingerprint' => $fingerprint,
            'signed_challenge' => $signedChallenge,
        ]);
    }

    public function startBackup(string $databaseName, string $encryptionKey): Response
    {
        return $this->client()->post('/api/backups/start', [
            'database_name' => $databaseName,
            'encryption_key' => $encryptionKey,
        ]);
    }

    public function updateProgress(int $backupId, int $progress): Response
    {
        return $this->client()->post("/api/backups/{$backupId}/progress", [
            'progress' => $progress,
        ]);
    }

    public function completeBackup(int $backupId, string $filename, int $size, int $tableCount, string $checksum): Response
    {
        return $this->client()->post("/api/backups/{$backupId}/complete", [
            'filename' => $filename,
            'size' => $size,
            'table_count' => $tableCount,
            'checksum' => $checksum,
        ]);
    }

    public function failBackup(int $backupId, string $errorMessage): Response
    {
        return $this->client()->post("/api/backups/{$backupId}/failed", [
            'error_message' => $errorMessage,
        ]);
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(5)
                ->get('/api/health');

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }
}
