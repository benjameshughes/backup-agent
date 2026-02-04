<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class RetryQueue
{
    protected string $queuePath;

    protected int $maxAttempts;

    protected int $baseDelay;

    public function __construct()
    {
        $this->queuePath = config('backup.storage_path').'/retry-queue.json';
        $this->maxAttempts = config('backup.retry.max_attempts', 5);
        $this->baseDelay = config('backup.retry.base_delay', 60);
    }

    /**
     * Add a failed API call to the retry queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function add(string $endpoint, string $method, array $payload): void
    {
        $queue = $this->load();

        $queue[] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'payload' => $payload,
            'attempts' => 0,
            'next_attempt_at' => time(),
            'created_at' => time(),
        ];

        $this->save($queue);
    }

    /**
     * Get all items ready for retry.
     *
     * @return array<int, array{endpoint: string, method: string, payload: array, attempts: int, next_attempt_at: int, created_at: int}>
     */
    public function getReadyItems(): array
    {
        $queue = $this->load();
        $now = time();

        return array_values(array_filter($queue, fn ($item) => $item['next_attempt_at'] <= $now && $item['attempts'] < $this->maxAttempts
        ));
    }

    /**
     * Mark an item as successfully processed.
     */
    public function markSuccess(int $index): void
    {
        $queue = $this->load();
        unset($queue[$index]);
        $this->save(array_values($queue));
    }

    /**
     * Mark an item as failed and schedule retry with exponential backoff.
     */
    public function markFailed(int $index): void
    {
        $queue = $this->load();

        if (isset($queue[$index])) {
            $queue[$index]['attempts']++;

            // Exponential backoff: base_delay * 2^attempts
            $delay = $this->baseDelay * pow(2, $queue[$index]['attempts']);
            $queue[$index]['next_attempt_at'] = time() + $delay;

            // Remove if max attempts exceeded
            if ($queue[$index]['attempts'] >= $this->maxAttempts) {
                unset($queue[$index]);
                $queue = array_values($queue);
            }

            $this->save($queue);
        }
    }

    /**
     * Get the count of items in the queue.
     */
    public function count(): int
    {
        return count($this->load());
    }

    /**
     * Load the queue from disk.
     *
     * @return array<int, array{endpoint: string, method: string, payload: array, attempts: int, next_attempt_at: int, created_at: int}>
     */
    protected function load(): array
    {
        if (! File::exists($this->queuePath)) {
            return [];
        }

        $content = File::get($this->queuePath);

        return json_decode($content, true) ?? [];
    }

    /**
     * Save the queue to disk.
     *
     * @param  array<int, array{endpoint: string, method: string, payload: array, attempts: int, next_attempt_at: int, created_at: int}>  $queue
     */
    protected function save(array $queue): void
    {
        $directory = dirname($this->queuePath);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($this->queuePath, json_encode($queue, JSON_PRETTY_PRINT));
    }
}
