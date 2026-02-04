<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class RsyncUploader
{
    protected string $destination;

    public function __construct()
    {
        $this->destination = config('backup.rsync_destination');
    }

    /**
     * Upload a file using rsync with progress callback.
     *
     * @param  callable(int): void  $progressCallback  Callback receiving progress percentage
     */
    public function upload(string $filePath, string $remotePath, callable $progressCallback): bool
    {
        $destination = rtrim($this->destination, '/').'/'.$remotePath;

        $command = sprintf(
            'rsync -avz --progress %s %s 2>&1',
            escapeshellarg($filePath),
            escapeshellarg($destination)
        );

        $process = Process::timeout(7200)->start($command);

        $lastProgress = 0;

        // Read output and parse progress
        while ($process->running()) {
            $output = $process->latestOutput();

            if ($output) {
                $progress = $this->parseProgress($output);

                if ($progress !== null && $progress !== $lastProgress) {
                    $lastProgress = $progress;
                    $progressCallback($progress);
                }
            }

            usleep(100000); // 100ms
        }

        $result = $process->wait();

        if ($result->successful()) {
            $progressCallback(100);

            return true;
        }

        return false;
    }

    /**
     * Parse rsync progress output.
     */
    protected function parseProgress(string $output): ?int
    {
        // rsync outputs progress like: "  1,234,567  50%  1.23MB/s  0:00:10"
        if (preg_match('/(\d+)%/', $output, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
