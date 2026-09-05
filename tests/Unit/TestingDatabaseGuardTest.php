<?php

namespace Tests\Unit;

use App\Support\TestingDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestingDatabaseGuardTest extends TestCase
{
    #[DataProvider('safeConnections')]
    public function test_host_port_and_user_can_vary(array $connection): void
    {
        TestingDatabaseGuard::validate(false, 'pgsql', $connection);
        $this->addToAssertionCount(1);
    }

    public static function safeConnections(): array
    {
        return [
            'Compose' => [['driver' => 'pgsql', 'database' => 'keiba_test', 'host' => 'postgres-test', 'port' => '5432', 'username' => 'keiba_test']],
            'CI' => [['driver' => 'pgsql', 'database' => 'keiba_test', 'host' => '127.0.0.1', 'port' => '15432', 'username' => 'ci_test']],
        ];
    }

    #[DataProvider('unsafeConnections')]
    public function test_unsafe_connections_are_rejected(bool $cached, string $default, array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        TestingDatabaseGuard::validate($cached, $default, array_replace([
            'driver' => 'pgsql', 'database' => 'keiba_test',
        ], $overrides));
    }

    public static function unsafeConnections(): array
    {
        return [
            'cached config' => [true, 'pgsql', []],
            'other connection' => [false, 'sqlite', []],
            'other driver' => [false, 'pgsql', ['driver' => 'mysql']],
            'dev database' => [false, 'pgsql', ['database' => 'keiba_dev']],
            'prod database' => [false, 'pgsql', ['database' => 'keiba_prod']],
            'perf database' => [false, 'pgsql', ['database' => 'keiba_perf']],
            'missing database' => [false, 'pgsql', ['database' => null]],
            'URL override' => [false, 'pgsql', ['url' => 'postgresql://localhost/keiba_prod']],
            'read override' => [false, 'pgsql', ['read' => ['database' => 'keiba_dev']]],
            'write override' => [false, 'pgsql', ['write' => ['database' => 'keiba_prod']]],
        ];
    }
}
