<?php

namespace App\Commands;

use App\Services\PanelApi;
use App\Services\RetryQueue;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class RetryProcessCommand extends Command
{
    protected $signature = 'retry:process';

    protected $description = 'Process pending items in the retry queue';

    public function handle(PanelApi $api, RetryQueue $retryQueue): int
    {
        $items = $retryQueue->getReadyItems();

        if (empty($items)) {
            info('Retry queue is empty.');

            return self::SUCCESS;
        }

        if (! $api->isAvailable()) {
            warning('Panel is not available. Retry will be attempted later.');

            return self::FAILURE;
        }

        info("Processing {$items} items from retry queue...");

        $processed = 0;
        $failed = 0;

        foreach ($items as $index => $item) {
            $this->line("  Processing: {$item['method']} {$item['endpoint']}");

            try {
                $response = Http::baseUrl(config('backup.panel_url'))
                    ->withToken(config('backup.api_token'))
                    ->timeout(30)
                    ->{strtolower($item['method'])}($item['endpoint'], $item['payload']);

                if ($response->successful()) {
                    $retryQueue->markSuccess($index);
                    $processed++;
                    $this->line('    <info>Success</info>');
                } else {
                    $retryQueue->markFailed($index);
                    $failed++;
                    $this->line('    <error>Failed: '.$response->status().'</error>');
                }
            } catch (\Exception $e) {
                $retryQueue->markFailed($index);
                $failed++;
                $this->line('    <error>Error: '.$e->getMessage().'</error>');
            }
        }

        $this->newLine();
        info("Processed: {$processed} successful, {$failed} failed");

        $remaining = $retryQueue->count();
        if ($remaining > 0) {
            $this->line("Remaining in queue: {$remaining}");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
