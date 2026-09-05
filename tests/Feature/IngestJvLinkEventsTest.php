<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestJvLinkEventsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/internal/v1/jvlink/events';

    private int $raceId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['internal.jvlink_token' => 'synthetic-test-token']);
        $venue = DB::table('venues')->insertGetId(['name' => 'Synthetic venue']);
        DB::table('source_identifiers')->insert([
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'entity_id' => $venue,
            'identifier_type' => 'venue_code', 'identifier_value' => '01',
        ]);
        $calendar = DB::table('race_calendars')->insertGetId([
            'venue_id' => $venue, 'race_date' => '2026-09-05', 'status' => 'scheduled',
        ]);
        $this->raceId = DB::table('races')->insertGetId([
            'race_calendar_id' => $calendar, 'race_no' => 1, 'status' => 'scheduled',
        ]);
    }

    public function test_authentication_and_strict_validation_fail_closed(): void
    {
        $this->postJson(self::ENDPOINT, $this->batch())->assertUnauthorized();
        $invalid = $this->batch();
        $invalid['events'][0]['extra'] = 'raw';
        $this->send($invalid)->assertUnprocessable();
        $invalid = $this->batch();
        $invalid['events'][0]['payload']['raw'] = 'paid record';
        $this->send($invalid)->assertUnprocessable();
        config(['internal.jvlink_token' => '']);
        $this->send($this->batch())->assertStatus(503);
        $this->assertDatabaseCount('jvlink_events', 0);
    }

    public function test_odds_insert_and_replay_are_idempotent_and_reuse_canonical_race(): void
    {
        $this->send($this->batch())->assertExactJson(['received' => 1, 'inserted' => 1, 'unchanged' => 0, 'conflicted' => 0]);
        $this->send($this->batch())->assertExactJson(['received' => 1, 'inserted' => 0, 'unchanged' => 1, 'conflicted' => 0]);
        $this->assertDatabaseCount('races', 1);
        $this->assertDatabaseCount('jvlink_events', 1);
        $this->assertDatabaseCount('race_odds_snapshots', 1);
        $this->assertDatabaseCount('race_odds_snapshot_items', 2);
        $this->assertSame($this->raceId, DB::table('race_odds_snapshots')->value('race_id'));
        $this->assertDatabaseHas('race_odds_snapshot_items', ['bet_type' => 'win', 'horse_no' => 1, 'horse_id' => null]);
        $this->assertDatabaseHas('race_odds_snapshot_items', ['bet_type' => 'place', 'odds_min' => '2.0', 'odds_max' => '2.4']);
        $this->assertDatabaseCount('horses', 0);
    }

    public function test_same_source_event_id_with_different_hash_is_a_conflict(): void
    {
        $this->send($this->batch())->assertOk();
        $changed = $this->batch();
        $changed['events'][0]['payload_sha256'] = str_repeat('b', 64);
        $this->send($changed)->assertConflict()->assertJsonPath('error_category', 'identity_conflict')->assertJsonPath('retryable', false);
        $this->assertDatabaseCount('jvlink_events', 1);
        $this->assertDatabaseCount('race_odds_snapshots', 1);
    }

    public function test_historical_and_realtime_snapshots_remain_distinct(): void
    {
        $batch = $this->batch();
        $historical = $batch['events'][0];
        $historical['source_event_id'] = 'jvlink:0B41:O1:history';
        $historical['source_data_spec'] = '0B41';
        $historical['payload_sha256'] = str_repeat('c', 64);
        $historical['payload']['source_kind'] = 'historical_timeseries';
        $batch['events'][] = $historical;
        $this->send($batch)->assertJsonPath('inserted', 2);
        $this->assertDatabaseHas('race_odds_snapshots', ['source_kind' => 'realtime']);
        $this->assertDatabaseHas('race_odds_snapshots', ['source_kind' => 'historical_timeseries']);
    }

    public function test_all_live_history_types_are_append_only_and_resolve_safely(): void
    {
        $batch = ['events' => [
            $this->event('runner-status', 'runner_status', $this->racePayload() + [
                'horse_no' => 1, 'status_type' => 'cancelled', 'reason_code' => '001',
            ]),
            $this->event('jockey-change', 'jockey_change', $this->racePayload() + [
                'horse_no' => 1, 'old_jockey_code' => null, 'old_jockey_name' => null,
                'new_jockey_code' => '54321', 'new_jockey_name' => null,
            ]),
            $this->event('body-weight', 'body_weight', $this->racePayload() + [
                'horse_no' => 1, 'body_weight' => 478, 'body_weight_delta' => -6, 'source_status_code' => null,
            ]),
            $this->event('weather', 'weather_track', [
                'race_date' => '2026-09-05', 'venue_code' => '01', 'change_type' => 'initial',
                'weather' => '2', 'turf_condition' => '3', 'dirt_condition' => '4',
            ]),
        ]];
        $this->send($batch)->assertJsonPath('inserted', 4);
        $this->assertDatabaseCount('runner_status_events', 1);
        $this->assertDatabaseCount('jockey_change_events', 1);
        $this->assertDatabaseCount('body_weight_snapshots', 1);
        $this->assertDatabaseCount('weather_track_events', 1);
        $this->assertDatabaseCount('horses', 0);
        $this->assertDatabaseCount('jockeys', 1);
    }

    public function test_unresolved_race_is_retryable_and_succeeds_once_after_canonical_race_arrives(): void
    {
        $event = $this->batch()['events'][0];
        $event['source_event_id'] = 'unresolved';
        $event['payload_sha256'] = str_repeat('d', 64);
        $event['payload']['race_no'] = 12;
        $this->send(['events' => [$event]])->assertConflict()
            ->assertJsonPath('error_category', 'canonical_dependency_missing')->assertJsonPath('retryable', true);
        $this->assertDatabaseCount('jvlink_events', 0);
        $this->assertDatabaseCount('race_odds_snapshots', 0);
        $calendarId = DB::table('races')->where('id', $this->raceId)->value('race_calendar_id');
        DB::table('races')->insert(['race_calendar_id' => $calendarId, 'race_no' => 12, 'status' => 'scheduled']);
        $this->send(['events' => [$event]])->assertExactJson(['received' => 1, 'inserted' => 1, 'unchanged' => 0, 'conflicted' => 0]);
        $this->send(['events' => [$event]])->assertExactJson(['received' => 1, 'inserted' => 0, 'unchanged' => 1, 'conflicted' => 0]);
        $this->assertDatabaseCount('jvlink_events', 1);
        $this->assertDatabaseCount('race_odds_snapshots', 1);
    }

    public function test_backfill_audit_and_coverage_reports_are_idempotent(): void
    {
        $report = [
            'source_run_id' => 'synthetic-run-1', 'requested_from' => '2008-01-01', 'requested_to' => '2008-01-02',
            'actual_min_date' => null, 'actual_max_date' => null, 'status' => 'completed',
            'races_requested' => 240, 'races_found' => 0, 'snapshots_inserted' => 0,
            'started_at' => '2026-09-05T00:00:00Z', 'finished_at' => '2026-09-05T00:10:00Z',
            'error_category' => null, 'coverages' => [[
                'source_race_key' => '200801010101', 'coverage_date' => '2008-01-01', 'venue_code' => '01', 'race_no' => 1,
                'data_kind' => 'win_place_timeseries', 'status' => 'no_data',
                'first_snapshot_at' => null, 'last_snapshot_at' => null, 'snapshot_count' => 0,
                'last_checked_at' => '2026-09-05T00:10:00Z',
            ]],
        ];
        $this->withToken('synthetic-test-token')->postJson('/api/internal/v1/jvlink/backfills', $report)->assertOk();
        $report['coverages'][0]['status'] = 'outside_provider_retention';
        $report['coverages'][] = $report['coverages'][0] + [];
        $report['coverages'][1]['source_race_key'] = '200801010102';
        $report['coverages'][1]['race_no'] = 2;
        $report['coverages'][1]['status'] = 'no_data';
        $this->withToken('synthetic-test-token')->postJson('/api/internal/v1/jvlink/backfills', $report)->assertOk();
        $this->assertDatabaseCount('jvlink_backfill_runs', 1);
        $this->assertDatabaseCount('jvlink_backfill_coverages', 2);
        $this->assertDatabaseHas('jvlink_backfill_coverages', ['status' => 'outside_provider_retention']);
        $this->assertDatabaseHas('jvlink_backfill_coverages', ['source_race_key' => '200801010102', 'status' => 'no_data', 'race_id' => null]);
    }

    private function send(array $payload): TestResponse
    {
        return $this->withToken('synthetic-test-token')->postJson(self::ENDPOINT, $payload);
    }

    private function batch(): array
    {
        return ['events' => [$this->event('odds', 'odds_snapshot', $this->racePayload() + [
            'source_kind' => 'realtime', 'snapshot_at' => '2026-09-05T10:30:00+09:00',
            'items' => [
                ['bet_type' => 'win', 'horse_no' => 1, 'odds' => 12.3, 'odds_min' => null, 'odds_max' => null, 'status' => null],
                ['bet_type' => 'place', 'horse_no' => 1, 'odds' => null, 'odds_min' => 2.0, 'odds_max' => 2.4, 'status' => null],
            ],
        ])]];
    }

    private function racePayload(): array
    {
        return ['race_date' => '2026-09-05', 'venue_code' => '01', 'race_no' => 1, 'jvlink_race_id' => '2026090501030501'];
    }

    private function event(string $id, string $type, array $payload): array
    {
        return [
            'source_event_id' => "jvlink:test:$id", 'event_type' => $type,
            'source_data_spec' => '0B14', 'source_record_type' => 'XX',
            'source_published_at' => '2026-09-05T10:30:00+09:00', 'effective_at' => null,
            'captured_at' => '2026-09-05T01:31:00Z', 'payload_sha256' => str_repeat('a', 64),
            'payload' => $payload,
        ];
    }
}
