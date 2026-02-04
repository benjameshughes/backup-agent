<?php

namespace App\Commands;

use App\Services\PanelApi;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    protected $signature = 'install
        {--name= : Server name (defaults to hostname)}
        {--panel-url= : Panel URL}';

    protected $description = 'Register this server with the backup panel';

    protected string $keyPath;

    protected string $configPath;

    public function handle(PanelApi $api): int
    {
        $this->keyPath = config('backup.storage_path').'/keys';
        $this->configPath = config('backup.storage_path').'/config.json';

        // Override panel URL if provided
        if ($panelUrl = $this->option('panel-url')) {
            config(['backup.panel_url' => $panelUrl]);
            $api = new PanelApi(); // Recreate with new config
        }

        info('Backup Agent Installation');

        // Check if already installed
        if ($this->isInstalled()) {
            warning('This server is already registered. Use --force to re-register.');

            return self::FAILURE;
        }

        // Generate keypair
        $keyPair = spin(
            fn () => $this->generateKeyPair(),
            'Generating encryption keypair...'
        );

        // Get server info
        $name = $this->option('name') ?? config('backup.server_name');
        $ipAddress = $this->getExternalIp();

        // Register with panel
        info("Registering server '{$name}' with panel...");

        $response = spin(
            fn () => $api->register($name, $keyPair['public'], $keyPair['fingerprint'], $ipAddress),
            'Contacting panel...'
        );

        if (! $response->successful()) {
            $this->error('Failed to register: '.$response->json('message', 'Unknown error'));

            return self::FAILURE;
        }

        $data = $response->json();

        // Save configuration
        $this->saveConfig([
            'panel_url' => config('backup.panel_url'),
            'server_id' => $data['server_id'],
            'fingerprint' => $data['fingerprint'],
            'status' => $data['status'],
            'registered_at' => now()->toIso8601String(),
        ]);

        $this->newLine();
        info('Registration successful!');
        $this->line("  Server ID: {$data['server_id']}");
        $this->line("  Fingerprint: {$data['fingerprint']}");
        $this->line("  Status: {$data['status']}");
        $this->newLine();

        if ($data['status'] === 'pending') {
            warning('Awaiting admin approval. Run `backup-agent status` to check.');
            $this->line('Once approved, run `backup-agent verify` to complete setup.');
        }

        return self::SUCCESS;
    }

    /**
     * Generate an RSA keypair.
     *
     * @return array{public: string, private: string, fingerprint: string}
     */
    protected function generateKeyPair(): array
    {
        if (! File::isDirectory($this->keyPath)) {
            File::makeDirectory($this->keyPath, 0700, true);
        }

        $privatePath = $this->keyPath.'/id_rsa';
        $publicPath = $this->keyPath.'/id_rsa.pub';

        // Generate keypair
        Process::run("ssh-keygen -t rsa -b 4096 -f {$privatePath} -N '' -q");

        $public = File::get($publicPath);
        $private = File::get($privatePath);

        // Calculate fingerprint
        $result = Process::run("ssh-keygen -lf {$publicPath}");
        preg_match('/SHA256:([^\s]+)/', $result->output(), $matches);
        $fingerprint = $matches[1] ?? hash('sha256', $public);

        return [
            'public' => $public,
            'private' => $private,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Get the server's external IP address.
     */
    protected function getExternalIp(): ?string
    {
        try {
            $result = Process::timeout(10)->run('curl -s https://api.ipify.org');

            if ($result->successful()) {
                return trim($result->output());
            }
        } catch (\Exception) {
            // Ignore
        }

        return null;
    }

    /**
     * Check if the agent is already installed.
     */
    protected function isInstalled(): bool
    {
        return File::exists($this->configPath);
    }

    /**
     * Save configuration to disk.
     *
     * @param  array<string, mixed>  $config
     */
    protected function saveConfig(array $config): void
    {
        $directory = dirname($this->configPath);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($this->configPath, json_encode($config, JSON_PRETTY_PRINT));
    }
}
