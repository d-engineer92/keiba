<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngestSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/internal/v1/jvlink/schedules';

    protected function setUp(): void
    {
        parent::setUp();
        config(['internal.jvlink_token' => 'synthetic-test-token']);
    }

    public function test_authentication_fails_closed(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        $this->withToken('wrong')->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        config(['internal.jvlink_token' => '']);
        $this->withToken('synthetic-test-token')->postJson(self::ENDPOINT, $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('venues', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_rejects_invalid_payloads(string $field, mixed $value): void
    {
        $payload = $this->payload();
        data_set($payload, $field, $value);
        $this->withToken('synthetic-test-token')->postJson(self::ENDPOINT, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('race_calendars', 0);
        $this->assertDatabaseCount('venues', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            ['captured_at', '2026-09-05'],
            ['captured_at', '2026-02-30T09:00:00+09:00'],
            ['schedules', []],
            ['schedules.0.venue_code', 1],
            ['schedules.0.venue_code', '1'],
            ['schedules.0.venue_name', str_repeat('a', 256)],
            ['schedules.0.race_date', '2026-02-30'],
            ['schedules.0.meeting_no', -1],
            ['schedules.0.meeting_no', '03'],
            ['schedules.0.meeting_day', 32768],
            ['schedules.0.status', 'unknown'],
            ['schedules.0.source_updated_at', 'not-a-date'],
            ['schedules.0.source_updated_at', '2026-09-05T09:00:00'],
            ['schedules.0.extra', 'unexpected'],
            ['extra', 'unexpected'],
        ];
    }

    public function test_insert_then_exact_replay_reuses_mapping_and_preserves_internal_ids(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertExactJson(['received' => 1, 'inserted' => 1, 'updated' => 0, 'unchanged' => 0]);
        $id = DB::table('race_calendars')->value('id');
        $this->send($payload)->assertExactJson(['received' => 1, 'inserted' => 0, 'updated' => 0, 'unchanged' => 1]);
        $this->assertDatabaseCount('venues', 1);
        $this->assertDatabaseCount('source_identifiers', 1);
        $this->assertDatabaseCount('race_calendars', 1);
        $this->assertSame($id, DB::table('race_calendars')->value('id'));
        $this->assertDatabaseHas('source_identifiers', [
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'identifier_type' => 'venue_code',
            'identifier_value' => '01', 'entity_id' => DB::table('venues')->value('id'),
        ]);
    }

    public function test_existing_name_is_reused_and_known_code_can_omit_name(): void
    {
        $venue = DB::table('venues')->insertGetId(['name' => 'Synthetic venue']);
        $this->send($this->payload())->assertOk();
        $payload = $this->payload();
        $payload['schedules'][0]['venue_name'] = null;
        $this->send($payload)->assertOk();
        $this->assertDatabaseCount('venues', 1);
        $this->assertSame($venue, DB::table('source_identifiers')->value('entity_id'));
    }

    public function test_changed_snapshot_updates_observation_but_preserves_first_seen(): void
    {
        $this->send($this->payload())->assertOk();
        $payload = $this->payload();
        $payload['captured_at'] = '2026-09-05T10:00:00+09:00';
        $payload['schedules'][0]['meeting_no'] = 4;
        $payload['schedules'][0]['status'] = 'completed';
        $payload['schedules'][0]['source_updated_at'] = '2026-09-05T09:30:00+09:00';
        $this->send($payload)->assertJsonPath('updated', 1);
        $row = DB::table('race_calendars')->first();
        $this->assertSame(4, $row->meeting_no);
        $this->assertSame('completed', $row->status);
        $this->assertTrue(CarbonImmutable::parse($row->first_seen_at)->equalTo('2026-09-05T09:00:00+09:00'));
        $this->assertTrue(CarbonImmutable::parse($row->last_seen_at)->equalTo($payload['captured_at']));
    }

    public function test_older_source_timestamp_cannot_rewind_snapshot_even_with_later_capture(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertOk();
        $payload['captured_at'] = '2026-09-06T09:00:00+09:00';
        $payload['schedules'][0]['source_updated_at'] = '2026-09-04T09:00:00+09:00';
        $payload['schedules'][0]['meeting_no'] = 1;
        $this->send($payload)->assertJsonPath('updated', 1);
        $row = DB::table('race_calendars')->first();
        $this->assertSame(3, $row->meeting_no);
        $this->assertTrue(CarbonImmutable::parse($row->source_updated_at)->equalTo('2026-09-05T08:00:00+09:00'));
        $this->assertTrue(CarbonImmutable::parse($row->last_seen_at)->equalTo($payload['captured_at']));
    }

    public function test_missing_source_timestamp_uses_capture_order_without_rewinding_last_seen(): void
    {
        $payload = $this->payload();
        $payload['schedules'][0]['source_updated_at'] = null;
        $this->send($payload)->assertOk();
        $payload['captured_at'] = '2026-09-04T09:00:00+09:00';
        $payload['schedules'][0]['meeting_no'] = 1;
        $this->send($payload)->assertJsonPath('unchanged', 1);
        $this->assertSame(3, DB::table('race_calendars')->value('meeting_no'));
    }

    public function test_mapping_conflict_and_orphan_are_rejected_without_reassignment(): void
    {
        $this->send($this->payload())->assertOk();
        $payload = $this->payload();
        $payload['schedules'][0]['venue_name'] = 'Conflicting name';
        $this->send($payload)->assertConflict();
        $this->assertDatabaseCount('venues', 1);
        DB::table('source_identifiers')->update(['entity_id' => 999999]);
        $this->send($this->payload())->assertConflict();
        $this->assertSame(999999, DB::table('source_identifiers')->value('entity_id'));
    }

    public function test_unknown_nameless_venue_rolls_back_entire_batch(): void
    {
        $payload = $this->payload();
        $payload['schedules'][] = array_replace($payload['schedules'][0], ['venue_code' => '02', 'venue_name' => null]);
        $this->send($payload)->assertConflict();
        $this->assertDatabaseCount('venues', 0);
        $this->assertDatabaseCount('source_identifiers', 0);
        $this->assertDatabaseCount('race_calendars', 0);
    }

    public function test_nullable_snapshot_values_and_duplicate_rows_in_one_batch(): void
    {
        $payload = $this->payload();
        foreach (['meeting_no', 'meeting_day', 'source_updated_at'] as $field) {
            $payload['schedules'][0][$field] = null;
        }
        $payload['schedules'][] = $payload['schedules'][0];
        $this->send($payload)->assertExactJson(['received' => 2, 'inserted' => 1, 'updated' => 0, 'unchanged' => 1]);
        $this->assertDatabaseCount('race_calendars', 1);
    }

    private function send(array $payload): TestResponse
    {
        return $this->withToken('synthetic-test-token')->postJson(self::ENDPOINT, $payload);
    }

    private function payload(): array
    {
        return [
            'captured_at' => '2026-09-05T09:00:00+09:00',
            'schedules' => [[
                'venue_code' => '01', 'venue_name' => 'Synthetic venue', 'race_date' => '2026-09-05',
                'meeting_no' => 3, 'meeting_day' => 5, 'status' => 'scheduled',
                'source_updated_at' => '2026-09-05T08:00:00+09:00',
            ]],
        ];
    }
}
