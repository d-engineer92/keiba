<?php

namespace App\Providers;

use App\Kd3\HttpKd3Gateway;
use App\Kd3\Kd3Gateway;
use App\Kd3\Kd3LzhExtractor;
use App\Kd3\ProcessKd3LzhExtractor;
use App\Support\TestingDatabaseGuard;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Kd3Gateway::class, HttpKd3Gateway::class);
        $this->app->bind(Kd3LzhExtractor::class, ProcessKd3LzhExtractor::class);
    }

    public function boot(): void
    {
        // Fail before any test trait can migrate or truncate an unintended DB.
        if ($this->app->environment('testing')
            || $this->app->runningUnitTests()
            || ($_ENV['APP_ENV'] ?? null) === 'testing'
            || ($this->app->runningInConsole() && (new ArgvInput)->getParameterOption('--env') === 'testing')) {
            TestingDatabaseGuard::validate(
                $this->app->configurationIsCached(),
                config('database.default'),
                config('database.connections.pgsql'),
            );
        }
    }
}
