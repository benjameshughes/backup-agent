<?php

namespace App\Commands;

use App\Services\BackupEncryptor;
use App\Services\DatabaseDumper;
use App\Services\PanelApi;
use App\Services\RetryQueue;
use App\Services\RsyncUploader;
use App\Services\SiteScanner;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class BackupCommand extends Command
{
    protected $signature = 'backup
        {--site= : Only backup specific site}
        {--database= : Only backup specific database}
        {--dry-run : Show what would be backed up without running}';

    protected $description = 'Run database backups for all Laravel sites';

    protected string $storagePath;

    public function handle(
        SiteScanner $scanner,
        DatabaseDumper $dumper,
        BackupEncryptor $encryptor,
        RsyncUploader $uploader,
        PanelApi $api,
        RetryQueue $retryQueue
    ): int {
        $this->storagePath = config('backup.storage_path');

        info('Backup Agent - Starting backup run');

        // Check panel availability
        if (! $api->isAvailable()) {
            warning('Panel is not available. Backups will be queued for retry.');
        }

        // Scan for sites
        $sites = spin(
            fn () => $scanner->scan(),
            'Scanning for Laravel sites...'
        );

        if ($sites->isEmpty()) {
            warning('No Laravel sites with MySQL databases found.');

            return self::SUCCESS;
        }

        $this->line("Found {$sites->count()} site(s) with databases.");
        $this->newLine();

        // Filter if specific site/database requested
        if ($site = $this->option('site')) {
            $sites = $sites->filter(fn ($s) => $s['site'] === $site);
        }

        if ($database = $this->option('database')) {
            $sites = $sites->filter(fn ($s) => $s['database'] === $database);
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Site', 'Database'],
                $sites->map(fn ($s) => [$s['site'], $s['database']])->toArray()
            );

            return self::SUCCESS;
        }

        // Process each site
        $successful = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $result = $this->backupDatabase(
                $site,
                $dumper,
                $encryptor,
                $uploader,
                $api,
                $retryQueue
            );

            if ($result) {
                $successful++;
            } else {
                $failed++;
            }
        }

        // Process retry queue
        $this->processRetryQueue($api, $retryQueue);

        $this->newLine();
        info("Backup run complete: {$successful} successful, {$failed} failed");

        if ($retryQueue->count() > 0) {
            warning("Retry queue has {$retryQueue->count()} pending items.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Backup a single database.
     *
     * @param  array{site: string, database: string, connection: array}  $site
     */
    protected function backupDatabase(
        array $site,
        DatabaseDumper $dumper,
        BackupEncryptor $encryptor,
        RsyncUploader $uploader,
        PanelApi $api,
        RetryQueue $retryQueue
    ): bool {
        $database = $site['database'];
        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "{$database}_{$timestamp}";

        $this->line("Processing: {$database}");

        try {
            // Generate encryption key
            $encryptionKey = $encryptor->generateKey();

            // Start backup in panel
            $backupId = null;

            if ($api->isAvailable()) {
                $startResponse = $api->startBackup($database, $encryptionKey);

                if ($startResponse->successful()) {
                    $backupId = $startResponse->json('backup_id');
                } else {
                    $retryQueue->add('/api/backups/start', 'POST', [
                        'database_name' => $database,
                        'encryption_key' => $encryptionKey,
                    ]);
                }
            }

            // Dump database
            $dumpPath = "{$this->storagePath}/dumps/{$filename}.sql";

            $this->task('Dumping database', function () use ($dumper, $site, $dumpPath, &$dumpResult) {
                $dumpResult = $dumper->dump($site['connection'], $dumpPath);

                return true;
            });

            // Encrypt backup
            $encryptedPath = "{$this->storagePath}/encrypted/{$filename}.sql.enc";

            $this->task('Encrypting backup', function () use ($encryptor, $dumpPath, $encryptedPath, $encryptionKey, &$encryptResult) {
                $encryptResult = $encryptor->encrypt($dumpPath, $encryptedPath, $encryptionKey);

                return true;
            });

            // Get file size
            $size = File::size($encryptedPath);

            // Upload via rsync
            $this->line('  Uploading backup...');

            $uploadSuccess = $uploader->upload(
                $encryptedPath,
                "{$database}/{$filename}.sql.enc",
                function ($progress) use ($api, $backupId, $retryQueue) {
                    $this->output->write("\r  Progress: {$progress}%");

                    if ($backupId && $api->isAvailable()) {
                        $response = $api->updateProgress($backupId, $progress);

                        if (! $response->successful()) {
                            $retryQueue->add("/api/backups/{$backupId}/progress", 'POST', [
                                'progress' => $progress,
                            ]);
                        }
                    }
                }
            );

            $this->newLine();

            if (! $uploadSuccess) {
                throw new \RuntimeException('Upload failed');
            }

            // Complete backup
            if ($backupId && $api->isAvailable()) {
                $response = $api->completeBackup(
                    $backupId,
                    "{$filename}.sql.enc",
                    $size,
                    $dumpResult['table_count'],
                    $encryptResult['checksum']
                );

                if (! $response->successful()) {
                    $retryQueue->add("/api/backups/{$backupId}/complete", 'POST', [
                        'filename' => "{$filename}.sql.enc",
                        'size' => $size,
                        'table_count' => $dumpResult['table_count'],
                        'checksum' => $encryptResult['checksum'],
                    ]);
                }
            }

            // Cleanup local files
            File::delete([$dumpPath, $encryptedPath]);

            info("  Completed: {$database}");

            return true;

        } catch (\Exception $e) {
            $this->error("  Failed: {$e->getMessage()}");

            // Report failure to panel
            if (isset($backupId) && $backupId && $api->isAvailable()) {
                $api->failBackup($backupId, $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Process any pending items in the retry queue.
     */
    protected function processRetryQueue(PanelApi $api, RetryQueue $retryQueue): void
    {
        $items = $retryQueue->getReadyItems();

        if (empty($items)) {
            return;
        }

        $this->newLine();
        $this->line('Processing retry queue...');

        foreach ($items as $index => $item) {
            if (! $api->isAvailable()) {
                break;
            }

            try {
                // For now, just mark as processed if panel is available
                // In a real implementation, we'd re-send the request
                $retryQueue->markSuccess($index);
            } catch (\Exception) {
                $retryQueue->markFailed($index);
            }
        }
    }
}
