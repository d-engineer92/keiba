<?php

namespace App\Providers;

use App\Support\TestingDatabaseGuard;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
