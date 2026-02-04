<?php

namespace App\Commands;

use App\Services\SiteScanner;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class ScanCommand extends Command
{
    protected $signature = 'scan
        {--paths : Show configured scan paths}';

    protected $description = 'Scan for Laravel sites and their databases';

    public function handle(SiteScanner $scanner): int
    {
        if ($this->option('paths')) {
            info('Configured scan paths:');
            foreach ($scanner->getPaths() as $path) {
                $exists = is_dir($path) ? '<info>exists</info>' : '<error>not found</error>';
                $this->line("  - {$path} ({$exists})");
            }

            return self::SUCCESS;
        }

        info('Scanning for Laravel sites...');
        $this->line('Paths: '.implode(', ', $scanner->getPaths()));
        $this->newLine();

        $sites = spin(
            fn () => $scanner->scan(),
            'Scanning directories...'
        );

        if ($sites->isEmpty()) {
            warning('No Laravel sites with MySQL databases found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Site', 'Path', 'Database', 'Host'],
            $sites->map(fn ($site) => [
                $site['site'],
                $site['path'],
                $site['database'],
                $site['connection']['host'],
            ])->toArray()
        );

        $this->newLine();
        info("Found {$sites->count()} site(s) with MySQL databases.");

        return self::SUCCESS;
    }
}
