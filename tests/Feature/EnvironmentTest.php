<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_checks_database_connectivity(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_health_returns_unavailable_when_database_fails(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andThrow(new \RuntimeException('Connection failed'));
        $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_business_date_processing_uses_asia_tokyo(): void
    {
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame('Asia/Tokyo', config('database.connections.pgsql.timezone'));

        $databaseTimezone = DB::selectOne("SELECT current_setting('TIMEZONE') AS timezone");
        $this->assertSame('Asia/Tokyo', $databaseTimezone?->timezone);

        $instant = CarbonImmutable::parse('2026-09-05 15:30:00', 'UTC');
        $this->assertSame('2026-09-06', $instant->setTimezone((string) config('app.timezone'))->toDateString());
    }
}
