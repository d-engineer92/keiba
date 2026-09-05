<?php

namespace App\Support;

use RuntimeException;

final class TestingDatabaseGuard
{
    /** @param array<string, mixed> $connection */
    public static function validate(bool $configurationCached, string $defaultConnection, array $connection): void
    {
        // No DB calls: rejection must happen before migrations or test traits run.
        if ($configurationCached
            || $defaultConnection !== 'pgsql'
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['database'] ?? null) !== 'keiba_test'
            || ! empty($connection['url'])
            || isset($connection['read'])
            || isset($connection['write'])) {
            throw new RuntimeException('Tests require uncached configuration and the isolated keiba_test PostgreSQL connection without URL or read/write overrides.');
        }
    }
}
