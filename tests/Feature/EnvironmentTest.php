<?php

namespace Tests\Feature;

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
}
