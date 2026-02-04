<?php

namespace App\Commands;

use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class ScanCommand extends Command
{
    protected $signature = 'scan';

    protected $description = 'Scan for Laravel sites and their databases';

    public function handle(SiteScanner $scanner): int
    {
        info('Scanning for Laravel sites...');

        $sites = spin(
            fn () => $scanner->scan(),
            'Scanning directories...'
        );

        if ($sites->isEmpty()) {
            warning('No Laravel sites with MySQL databases found.');
            $this->line('Checked path: '.config('backup.sites_path'));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Site', 'Database', 'Host', 'Port'],
            $sites->map(fn ($site) => [
                $site['site'],
                $site['database'],
                $site['connection']['host'],
                $site['connection']['port'],
            ])->toArray()
        );

        $this->newLine();
        info("Found {$sites->count()} site(s) with MySQL databases.");

        return self::SUCCESS;
    }
}
