<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_column_types_and_indexes(): void
    {
        $this->assertSame('keiba_test', DB::selectOne('SELECT current_database() AS name')->name);
        $expected = [
            'venues' => ['id' => 'bigint', 'name' => 'character varying', 'is_active' => 'boolean'],
            'race_calendars' => [
                'id' => 'bigint', 'venue_id' => 'bigint', 'race_date' => 'date',
                'meeting_no' => 'smallint', 'meeting_day' => 'smallint', 'status' => 'character varying',
                'first_seen_at' => 'timestamp with time zone', 'last_seen_at' => 'timestamp with time zone',
                'source_updated_at' => 'timestamp with time zone',
            ],
            'races' => [
                'id' => 'bigint', 'race_calendar_id' => 'bigint', 'race_no' => 'smallint',
                'name' => 'character varying', 'status' => 'character varying',
                'scheduled_start_at' => 'timestamp with time zone',
            ],
            'source_identifiers' => [
                'id' => 'bigint', 'source_system' => 'character varying', 'entity_type' => 'character varying',
                'entity_id' => 'bigint', 'identifier_type' => 'character varying', 'identifier_value' => 'character varying',
                'first_seen_at' => 'timestamp with time zone', 'last_seen_at' => 'timestamp with time zone',
            ],
        ];
        foreach ($expected as $table => $columns) {
            $columns += ['created_at' => 'timestamp with time zone', 'updated_at' => 'timestamp with time zone'];
            $actual = DB::table('information_schema.columns')
                ->where('table_schema', 'public')->where('table_name', $table)
                ->pluck('data_type', 'column_name')->all();
            ksort($columns);
            ksort($actual);
            $this->assertSame($columns, $actual, $table);
            $this->assertTrue(Schema::hasIndex($table, ['id'], 'primary'));
        }

        $this->assertTrue(Schema::hasIndex('race_calendars', ['race_date']));
        $this->assertTrue(Schema::hasIndex('source_identifiers', ['entity_type', 'entity_id']));
        $this->assertSame([], Schema::getForeignKeys('source_identifiers'));
    }

    public function test_defaults_nullable_fields_and_positive_race_numbers_without_a_twelve_race_limit(): void
    {
        $venue = $this->venue();
        $calendar = $this->calendar($venue);
        $race = DB::table('races')->insertGetId(['race_calendar_id' => $calendar, 'race_no' => 13]);
        $this->assertTrue(DB::table('venues')->find($venue)->is_active);
        $this->assertSame('scheduled', DB::table('race_calendars')->find($calendar)->status);
        $this->assertNull(DB::table('race_calendars')->find($calendar)->meeting_no);
        $this->assertNull(DB::table('race_calendars')->find($calendar)->meeting_day);
        $this->assertNull(DB::table('race_calendars')->find($calendar)->first_seen_at);
        $this->assertNull(DB::table('race_calendars')->find($calendar)->last_seen_at);
        $this->assertNull(DB::table('race_calendars')->find($calendar)->source_updated_at);
        $this->assertSame('scheduled', DB::table('races')->find($race)->status);
        $this->assertNull(DB::table('races')->find($race)->name);
        $this->assertNull(DB::table('races')->find($race)->scheduled_start_at);
        DB::table('races')->where('id', $race)->update(['status' => 'future_status']);
        $this->assertSame('future_status', DB::table('races')->find($race)->status);
    }

    #[DataProvider('invalidWrites')]
    public function test_database_rejects_invalid_writes(string $operation, string $sqlState): void
    {
        $venue = $this->venue();
        $calendar = $this->calendar($venue);
        DB::table('races')->insert(['race_calendar_id' => $calendar, 'race_no' => 1]);
        $identifier = $this->identifier($venue);
        DB::table('source_identifiers')->insert($identifier);

        try {
            // A savepoint rolls back PostgreSQL's failed transaction before assertions/teardown.
            DB::transaction(function () use ($operation, $venue, $calendar, $identifier): void {
                match ($operation) {
                    'duplicate venue' => $this->venue(),
                    'orphan calendar' => $this->calendar(-1),
                    'orphan race' => DB::table('races')->insert(['race_calendar_id' => -1, 'race_no' => 1]),
                    'delete venue' => DB::table('venues')->where('id', $venue)->delete(),
                    'delete calendar' => DB::table('race_calendars')->where('id', $calendar)->delete(),
                    'duplicate calendar' => $this->calendar($venue),
                    'duplicate race' => DB::table('races')->insert(['race_calendar_id' => $calendar, 'race_no' => 1]),
                    'zero race' => DB::table('races')->insert(['race_calendar_id' => $calendar, 'race_no' => 0]),
                    'negative race' => DB::table('races')->insert(['race_calendar_id' => $calendar, 'race_no' => -1]),
                    'duplicate mapping' => DB::table('source_identifiers')->insert(array_replace($identifier, ['entity_id' => $venue + 1])),
                };
            });
            $this->fail('The database accepted '.$operation);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    public static function invalidWrites(): array
    {
        return [
            ['duplicate venue', '23505'],
            ['orphan calendar', '23503'],
            ['orphan race', '23503'],
            ['delete venue', '23001'],
            ['delete calendar', '23001'],
            ['duplicate calendar', '23505'],
            ['duplicate race', '23505'],
            ['zero race', '23514'],
            ['negative race', '23514'],
            ['duplicate mapping', '23505'],
        ];
    }

    public function test_uniqueness_is_scoped_to_calendar_and_identifier_dimensions(): void
    {
        $venue = $this->venue();
        $otherVenue = $this->venue('Other test venue');
        $calendar = $this->calendar($venue);
        $otherCalendar = $this->calendar($otherVenue);
        $this->calendar($venue, '2026-09-06');
        foreach ([$calendar, $otherCalendar] as $id) {
            DB::table('races')->insert(['race_calendar_id' => $id, 'race_no' => 1]);
        }
        $identifier = $this->identifier($venue);
        DB::table('source_identifiers')->insert($identifier);
        foreach ([
            ['source_system' => 'kd3'],
            ['entity_type' => 'race'],
            ['identifier_type' => 'alternate_code'],
            ['identifier_value' => 'synthetic-alias'],
        ] as $difference) {
            DB::table('source_identifiers')->insert(array_replace($identifier, $difference));
        }
        $this->assertDatabaseCount('race_calendars', 3);
        $this->assertDatabaseCount('races', 2);
        $this->assertDatabaseCount('source_identifiers', 5);
        $this->assertSame(4, DB::table('source_identifiers')->where('entity_type', 'venue')->where('entity_id', $venue)->count());
    }

    public function test_scheduled_timestamp_preserves_the_instant_across_offsets(): void
    {
        $calendar = $this->calendar($this->venue());
        DB::table('races')->insert([
            'race_calendar_id' => $calendar, 'race_no' => 1,
            'scheduled_start_at' => '2026-09-05 15:00:00+09:00',
        ]);
        $row = DB::selectOne("SELECT scheduled_start_at = TIMESTAMPTZ '2026-09-05 06:00:00+00' AS same_instant FROM races");
        $this->assertTrue($row->same_instant);
    }

    private function venue(string $name = 'Synthetic test venue'): int
    {
        return DB::table('venues')->insertGetId(['name' => $name]);
    }

    private function calendar(int $venue, string $date = '2026-09-05'): int
    {
        return DB::table('race_calendars')->insertGetId(['venue_id' => $venue, 'race_date' => $date]);
    }

    private function identifier(int $entity): array
    {
        return [
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'entity_id' => $entity,
            'identifier_type' => 'venue_code', 'identifier_value' => 'synthetic-venue-code',
        ];
    }
}
