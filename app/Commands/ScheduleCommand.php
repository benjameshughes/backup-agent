<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class ScheduleCommand extends Command
{
    protected $signature = 'schedule
        {--show : Show current cron setup}';

    protected $description = 'Show cron schedule setup instructions';

    public function handle(): int
    {
        $binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? base_path('backup-agent'));

        info('Backup Scheduling');
        $this->newLine();

        $this->line('Add the following to your crontab (crontab -e):');
        $this->newLine();

        // Daily at 2 AM
        $this->line('<comment># Run backups daily at 2:00 AM</comment>');
        $this->line("0 2 * * * {$binaryPath} backup >> /var/log/backup-agent.log 2>&1");
        $this->newLine();

        // Alternative: every 6 hours
        $this->line('<comment># Alternative: Run backups every 6 hours</comment>');
        $this->line("0 */6 * * * {$binaryPath} backup >> /var/log/backup-agent.log 2>&1");
        $this->newLine();

        // Retry queue processing
        $this->line('<comment># Process retry queue every 5 minutes</comment>');
        $this->line("*/5 * * * * {$binaryPath} retry:process >> /var/log/backup-agent.log 2>&1");
        $this->newLine();

        info('Tips:');
        $this->line('  - Adjust the schedule based on your backup frequency needs');
        $this->line('  - Ensure the log file is writable');
        $this->line('  - Consider using logrotate for the log file');

        return self::SUCCESS;
    }
}
