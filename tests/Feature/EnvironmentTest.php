<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_on_the_isolated_postgresql_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('keiba_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('keiba_test', DB::selectOne('SELECT current_user AS name')->name);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('jobs'));
    }

    public function test_health_checks_database_connectivity(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_health_returns_unavailable_when_database_fails(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andThrow(new \RuntimeException('Connection failed'));
        $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }
}
