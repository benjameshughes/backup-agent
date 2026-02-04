<?php

namespace App\Providers;

use App\Services\BackupEncryptor;
use App\Services\DatabaseDumper;
use App\Services\PanelApi;
use App\Services\RetryQueue;
use App\Services\RsyncUploader;
use App\Services\SiteScanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PanelApi::class);
        $this->app->singleton(SiteScanner::class);
        $this->app->singleton(DatabaseDumper::class);
        $this->app->singleton(BackupEncryptor::class);
        $this->app->singleton(RsyncUploader::class);
        $this->app->singleton(RetryQueue::class);
    }
}
