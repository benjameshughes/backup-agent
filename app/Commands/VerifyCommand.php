<?php

namespace App\Commands;

use App\Services\PanelApi;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class VerifyCommand extends Command
{
    protected $signature = 'verify';

    protected $description = 'Complete server verification with the panel';

    protected string $configPath;

    protected string $keyPath;

    public function handle(PanelApi $api): int
    {
        $this->configPath = config('backup.storage_path').'/config.json';
        $this->keyPath = config('backup.storage_path').'/keys/id_rsa';

        if (! $this->isInstalled()) {
            warning('Server not registered. Run `backup-agent install` first.');

            return self::FAILURE;
        }

        $config = $this->loadConfig();

        // First check status to get challenge
        info('Getting verification challenge...');

        $statusResponse = spin(
            fn () => $api->checkStatus($config['fingerprint']),
            'Contacting panel...'
        );

        if (! $statusResponse->successful()) {
            $this->error('Failed to get status: '.$statusResponse->json('message', 'Unknown error'));

            return self::FAILURE;
        }

        $statusData = $statusResponse->json();

        if ($statusData['status'] === 'pending') {
            warning('Server still pending approval. Please wait for admin approval.');

            return self::FAILURE;
        }

        if ($statusData['status'] === 'rejected') {
            $this->error('Server was rejected.');

            return self::FAILURE;
        }

        if ($statusData['status'] === 'verified') {
            info('Server is already verified!');

            return self::SUCCESS;
        }

        if (! isset($statusData['challenge'])) {
            $this->error('No challenge provided. Server may not be approved yet.');

            return self::FAILURE;
        }

        // Sign the challenge
        info('Signing verification challenge...');

        $signedChallenge = spin(
            fn () => $this->signChallenge($statusData['challenge']),
            'Signing with private key...'
        );

        // Send verification
        info('Verifying with panel...');

        $verifyResponse = spin(
            fn () => $api->verify($config['fingerprint'], $signedChallenge),
            'Sending verification...'
        );

        if (! $verifyResponse->successful()) {
            $this->error('Verification failed: '.$verifyResponse->json('message', 'Unknown error'));

            return self::FAILURE;
        }

        $verifyData = $verifyResponse->json();

        // Save API token
        $config['status'] = 'verified';
        $config['api_token'] = $verifyData['api_token'];
        $config['verified_at'] = now()->toIso8601String();
        $this->saveConfig($config);

        // Update .env with API token
        $this->updateEnvFile($verifyData['api_token']);

        $this->newLine();
        info('Server verified successfully!');
        $this->line('API token has been saved. You can now run backups.');
        $this->newLine();
        $this->line('To run a backup: backup-agent backup');
        $this->line('To schedule backups: backup-agent schedule');

        return self::SUCCESS;
    }

    /**
     * Sign the challenge with the private key.
     */
    protected function signChallenge(string $challenge): string
    {
        $signaturePath = sys_get_temp_dir().'/backup-challenge-sig';
        $challengePath = sys_get_temp_dir().'/backup-challenge';

        File::put($challengePath, $challenge);

        Process::run("openssl dgst -sha256 -sign {$this->keyPath} -out {$signaturePath} {$challengePath}");

        $signature = base64_encode(File::get($signaturePath));

        // Cleanup
        File::delete([$challengePath, $signaturePath]);

        return $signature;
    }

    /**
     * Update .env file with API token.
     */
    protected function updateEnvFile(string $apiToken): void
    {
        $envPath = base_path('.env');

        if (File::exists($envPath)) {
            $content = File::get($envPath);

            if (str_contains($content, 'BACKUP_API_TOKEN=')) {
                $content = preg_replace('/BACKUP_API_TOKEN=.*/', "BACKUP_API_TOKEN={$apiToken}", $content);
            } else {
                $content .= "\nBACKUP_API_TOKEN={$apiToken}";
            }

            File::put($envPath, $content);
        }
    }

    protected function isInstalled(): bool
    {
        return File::exists($this->configPath);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadConfig(): array
    {
        return json_decode(File::get($this->configPath), true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function saveConfig(array $config): void
    {
        File::put($this->configPath, json_encode($config, JSON_PRETTY_PRINT));
    }
}
