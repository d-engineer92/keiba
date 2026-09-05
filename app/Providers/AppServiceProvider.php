<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail before any test trait can migrate or truncate an unintended DB.
        if ($this->app->environment('testing') || $this->app->runningUnitTests() || ($_ENV['APP_ENV'] ?? null) === 'testing') {
            $connection = config('database.connections.pgsql');

            if ($this->app->configurationIsCached()
                || config('database.default') !== 'pgsql'
                || ! empty($connection['url'])
                || $connection['host'] !== 'postgres-test'
                || (string) $connection['port'] !== '5432'
                || $connection['database'] !== 'keiba_test'
                || $connection['username'] !== 'keiba_test') {
                throw new RuntimeException('Tests require uncached configuration and the isolated keiba_test PostgreSQL connection.');
            }
        }
    }
}
