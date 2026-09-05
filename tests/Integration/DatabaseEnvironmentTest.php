<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_on_the_isolated_postgresql_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('keiba_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(config('database.connections.pgsql.username'), DB::selectOne('SELECT current_user AS name')->name);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('jobs'));
    }
}
