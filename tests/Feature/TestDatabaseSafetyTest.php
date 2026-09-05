<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestDatabaseSafetyTest extends TestCase
{
    #[DataProvider('unsafeConnections')]
    public function test_provider_rejects_unsafe_configuration(string $key, mixed $value): void
    {
        config([$key => $value]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated keiba_test');
        (new AppServiceProvider($this->app))->boot();
    }

    public static function unsafeConnections(): array
    {
        return [
            'other connection' => ['database.default', 'sqlite'],
            'other driver' => ['database.connections.pgsql.driver', 'mysql'],
            'dev database' => ['database.connections.pgsql.database', 'keiba_dev'],
            'prod database' => ['database.connections.pgsql.database', 'keiba_prod'],
            'URL override' => ['database.connections.pgsql.url', 'postgresql://localhost/keiba_prod'],
            'read override' => ['database.connections.pgsql.read', ['database' => 'keiba_dev']],
            'write override' => ['database.connections.pgsql.write', ['database' => 'keiba_prod']],
        ];
    }

    #[DataProvider('unsafeEnvironment')]
    public function test_migration_and_phpunit_reject_unsafe_environment_before_connecting(array $environment): void
    {
        // An unreachable endpoint proves rejection happens before database access.
        $environment = array_replace($environment, ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
        foreach ([
            [PHP_BINARY, 'artisan', 'migrate:fresh', '--env=testing', '--no-interaction'],
            [PHP_BINARY, 'vendor/bin/phpunit', '--filter=test_migrations_run_on_the_isolated_postgresql_database'],
        ] as $command) {
            $process = new Process($command, base_path(), $environment);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('isolated keiba_test', $process->getOutput().$process->getErrorOutput());
        }
    }

    public static function unsafeEnvironment(): array
    {
        return [
            'dev database' => [['DB_DATABASE' => 'keiba_dev']],
            'prod database' => [['DB_DATABASE' => 'keiba_prod']],
            'SQLite' => [['DB_CONNECTION' => 'sqlite']],
            'URL override' => [['DB_URL' => 'postgresql://localhost/keiba_prod']],
        ];
    }

    public function test_cached_local_configuration_cannot_bypass_testing_guard(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'keiba-config-');
        $configuration = config()->all();
        $configuration['app']['env'] = 'local';
        $configuration['database']['connections']['pgsql']['database'] = 'keiba_dev';
        file_put_contents($path, '<?php return '.var_export($configuration, true).';');

        try {
            foreach ([
                [PHP_BINARY, 'vendor/bin/phpunit', '--filter=test_migrations_run_on_the_isolated_postgresql_database'],
                [PHP_BINARY, 'artisan', 'migrate:fresh', '--env=testing', '--no-interaction'],
            ] as $command) {
                $process = new Process($command, base_path(), [
                    'APP_CONFIG_CACHE' => $path,
                    'APP_ENV' => false,
                ]);
                $process->run();
                $this->assertFalse($process->isSuccessful());
                $this->assertStringContainsString('isolated keiba_test', $process->getOutput().$process->getErrorOutput());
            }
        } finally {
            unlink($path);
        }
    }
}
