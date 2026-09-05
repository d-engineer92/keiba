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
    public function test_unsafe_test_connections_are_rejected(string $key, string $value): void
    {
        config([$key => $value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated keiba_test');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_phpunit_overrides_inherited_development_connection_variables(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('vendor/bin/phpunit'),
            '--filter=test_migrations_run_on_the_isolated_postgresql_database',
        ], base_path(), [
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5433',
            'DB_DATABASE' => 'keiba_dev',
            'DB_USERNAME' => 'keiba_dev',
            'DB_PASSWORD' => 'wrong-test-password',
            'DB_URL' => 'postgresql://keiba_dev@postgres:5433/keiba_dev',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    public static function unsafeConnections(): array
    {
        return [
            'other driver' => ['database.default', 'sqlite'],
            'dev host' => ['database.connections.pgsql.host', 'postgres'],
            'other port' => ['database.connections.pgsql.port', '5433'],
            'dev database' => ['database.connections.pgsql.database', 'keiba_dev'],
            'production database' => ['database.connections.pgsql.database', 'keiba_prod'],
            'dev user' => ['database.connections.pgsql.username', 'keiba_dev'],
            'URL override' => ['database.connections.pgsql.url', 'postgresql://postgres/keiba_prod'],
        ];
    }
}
