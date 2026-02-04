<?php

namespace App\Commands;

use App\Services\PanelApi;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Check server registration status with the panel';

    protected string $configPath;

    public function handle(PanelApi $api): int
    {
        $this->configPath = config('backup.storage_path').'/config.json';

        if (! $this->isInstalled()) {
            warning('Server not registered. Run `backup-agent install` first.');

            return self::FAILURE;
        }

        $config = $this->loadConfig();

        info('Checking registration status...');

        $response = spin(
            fn () => $api->checkStatus($config['fingerprint']),
            'Contacting panel...'
        );

        if (! $response->successful()) {
            $this->error('Failed to check status: '.$response->json('message', 'Unknown error'));

            return self::FAILURE;
        }

        $data = $response->json();

        // Update local config
        $config['status'] = $data['status'];

        if (isset($data['api_token'])) {
            $config['api_token'] = $data['api_token'];
        }

        if (isset($data['challenge'])) {
            $config['challenge'] = $data['challenge'];
        }

        $this->saveConfig($config);

        $this->newLine();
        $this->line("Server ID: {$config['server_id']}");
        $this->line("Status: {$data['status']}");
        $this->newLine();

        if ($data['status'] === 'pending') {
            warning('Awaiting admin approval.');
        } elseif ($data['status'] === 'approved' && isset($data['challenge'])) {
            info('Server approved! Run `backup-agent verify` to complete setup.');
        } elseif ($data['status'] === 'rejected') {
            $this->error('Server was rejected by admin.');

            return self::FAILURE;
        } elseif ($data['status'] === 'verified') {
            info('Server is verified and ready to run backups.');
        }

        return self::SUCCESS;
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
