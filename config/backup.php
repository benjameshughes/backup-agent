<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Panel Configuration
    |--------------------------------------------------------------------------
    */
    'panel_url' => env('BACKUP_PANEL_URL', 'https://backups.example.com'),
    'api_token' => env('BACKUP_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Sites Paths
    |--------------------------------------------------------------------------
    | Directories to scan for Laravel sites. Supports:
    | - Single path: /home/forge
    | - Multiple paths (comma-separated): /home/forge,/var/www,/srv/sites
    | - Common defaults for Forge, Ploi, RunCloud, etc.
    */
    'sites_paths' => env('BACKUP_SITES_PATHS', env('BACKUP_SITES_PATH', '/home/forge')),

    /*
    |--------------------------------------------------------------------------
    | Local Storage
    |--------------------------------------------------------------------------
    */
    'storage_path' => env('BACKUP_STORAGE_PATH', '/tmp/backups'),

    /*
    |--------------------------------------------------------------------------
    | Rsync Destination
    |--------------------------------------------------------------------------
    */
    'rsync_destination' => env('BACKUP_RSYNC_DESTINATION'),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts' => 5,
        'base_delay' => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Name
    |--------------------------------------------------------------------------
    */
    'server_name' => env('BACKUP_SERVER_NAME', gethostname()),
];
