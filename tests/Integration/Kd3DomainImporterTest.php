<?php

namespace Tests\Integration;

use App\Kd3\Domain\Kd3DomainImporter;
use App\Kd3\Kd3ParsedPackage;
use App\Kd3\Kd3ParsedRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Kd3DomainImporterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_hb_import_stores_only_non_blank_scaled_speed_values_and_is_idempotent(): void
    {
        $source = $this->source('hb', now());
        $package = $this->hbPackage($source, '0000001', [
            ['sequence' => 1, 'speed_index' => '500'],
            ['sequence' => 2, 'speed_index' => null],
            ['sequence' => 3, 'speed_index' => '700'],
            ['sequence' => 4, 'speed_index' => '800'],
            ['sequence' => 5, 'speed_index' => '867'],
        ]);

        $importer = $this->app->make(Kd3DomainImporter::class);
        $first = $importer->import($package, $source);
        DB::table('runner_speed_indices')->where('central_flat_run_back', 1)->update(['speed_index' => 867]);
        $second = $importer->import($package, $source);

        $this->assertGreaterThan(0, $first->inserted);
        $this->assertGreaterThan(0, $second->updated);
        $this->assertGreaterThan(0, $second->unchanged);
        $this->assertDatabaseCount('race_entries', 1);
        $this->assertDatabaseCount('race_entry_runners', 1);
        $this->assertDatabaseCount('runner_workouts', 1);
        $this->assertDatabaseCount('runner_speed_indices', 4);
        $this->assertDatabaseHas('runner_speed_indices', ['central_flat_run_back' => 1, 'speed_index' => '86.7']);
        $this->assertDatabaseMissing('runner_speed_indices', ['central_flat_run_back' => 4]);
        $this->assertDatabaseCount('runner_speed_index_references', 0);
        $this->assertDatabaseCount('race_speed_statistics', 5);
    }

    public function test_speed_reference_resolver_uses_canonical_results_and_skips_non_eligible_runs(): void
    {
        $targetSource = $this->source('hb', now());
        $target = $this->hbPackage($targetSource, '0000101', [
            ['sequence' => 1, 'speed_index' => '500'],
            ['sequence' => 2, 'speed_index' => '600'],
            ['sequence' => 3, 'speed_index' => '700'],
            ['sequence' => 4, 'speed_index' => '800'],
            ['sequence' => 5, 'speed_index' => '900'],
        ]);
        $importer = $this->app->make(Kd3DomainImporter::class);
        $importer->import($target, $targetSource);

        $horseId = (int) DB::table('source_identifiers')->where([
            'source_system' => 'kd3',
            'entity_type' => 'horse',
            'identifier_type' => 'horse_code',
            'identifier_value' => '0000101',
        ])->value('entity_id');
        $eligible1 = $this->resultRunner($horseId, '20260830', '0', '0', null, '1345');
        $this->resultRunner($horseId, '20260823', '4', '0', null, '1345');
        $this->resultRunner($horseId, '20260816', '0', '1', null, '3345');
        $this->resultRunner($horseId, '20260809', '0', '0', '34', null);
        $this->resultRunner($horseId, '20260802', '0', '0', '33', null);
        $eligible2 = $this->resultRunner($horseId, '20260726', '0', '0', '32', '1345');
        $eligible3 = $this->resultRunner($horseId, '20260719', '0', '0', '36', '1345');
        $eligible4 = $this->resultRunner($horseId, '20260712', '0', '0', '37', '1345');
        $eligible5 = $this->resultRunner($horseId, '20260705', '0', '0', null, '1345');

        // A known central race date without a current successful IB import makes every older
        // candidate unsafe, but does not invalidate a newer candidate that is already covered.
        $this->centralCalendar('20260827');
        $gapSource = $this->source('ib', now()->addSeconds(20), '2026-08-27');
        $importer->reconcile();
        $this->assertDatabaseCount('runner_speed_index_references', 1);
        $firstReference = DB::table('runner_speed_indices as speed')
            ->join('runner_speed_index_references as ref', 'ref.runner_speed_index_id', '=', 'speed.id')
            ->where('speed.central_flat_run_back', 1)
            ->value('ref.reference_race_result_runner_id');
        $this->assertSame($eligible1, (int) $firstReference);

        $this->markImportedIbCoverage('20260827', (int) $gapSource->id);
        $importer->reconcile();

        $expected = [1 => $eligible1, 2 => $eligible2, 3 => $eligible3, 4 => $eligible4, 5 => $eligible5];
        foreach ($expected as $slot => $referenceRunnerId) {
            $actual = DB::table('runner_speed_indices as speed')
                ->join('runner_speed_index_references as ref', 'ref.runner_speed_index_id', '=', 'speed.id')
                ->where('speed.central_flat_run_back', $slot)
                ->value('ref.reference_race_result_runner_id');
            $this->assertSame($referenceRunnerId, (int) $actual, 'Unexpected reference for central-flat slot '.$slot);
        }
        $this->assertDatabaseCount('runner_speed_index_references', 5);
    }

    public function test_ib_import_persists_result_classification_and_cancellation_type(): void
    {
        $source = $this->source('ib', now());
        $race = $this->raceFields();
        $horse = $this->horseRecord($source, 'ib', '0000201', '結果馬');
        $header = $this->record($source, 'ib', 'kol_sei1.kd3', array_merge($race, [
            'source_category_code' => '0', 'discipline_code' => '0', 'runner_count' => 1, 'cancelled_runner_count' => 1,
            'pace_code' => '1', 'weather_code' => '0', 'track_condition_code' => '0',
        ]));
        $runner = $this->record($source, 'ib', 'kol_sei2.kd3', array_merge($race, [
            'horse_no' => 1, 'horse_code' => '0000201', 'horse_name' => '結果馬', 'jockey_code' => null, 'jockey_name' => null,
            'trainer_code' => null, 'trainer_name' => null, 'finish_position' => null, 'finish_status_code' => '34',
            'cancellation_type_code' => '1', 'finish_time' => null, 'margin_whole' => null, 'margin_code' => null,
            'passing_1' => null, 'passing_2' => null, 'passing_3' => null, 'passing_4' => null, 'last_3f_tenths' => null,
            'body_weight' => null, 'body_weight_delta' => null, 'final_odds_tenths' => null, 'popularity' => null,
        ]));

        $package = $this->package($source, 'ib', [
            'kol_uma.kd3' => [$horse], 'kol_sei1.kd3' => [$header], 'kol_sei2.kd3' => [$runner],
        ]);
        $importer = $this->app->make(Kd3DomainImporter::class);
        $importer->import($package, $source);
        DB::table('race_results')->update(['source_category_code' => null, 'discipline_code' => null]);
        DB::table('race_result_runners')->update(['cancellation_type_code' => null]);
        $importer->import($package, $source);

        $this->assertDatabaseHas('race_result_runners', [
            'finish_status_code' => '34', 'cancellation_type_code' => '1', 'finish_time_tenths' => null,
        ]);
        $this->assertDatabaseHas('race_results', ['source_category_code' => '0', 'discipline_code' => '0']);
    }

    public function test_forecast_odds_and_unresolved_comments_are_retained(): void
    {
        $oddsSource = $this->source('jb', now());
        $items = array_map(static fn (int $sequence): array => [
            'sequence' => $sequence, 'odds_raw' => $sequence === 1 ? '00015' : '     ',
        ], range(1, 18));
        $oddsRecord = $this->record($oddsSource, 'jb', 'kol_ods.kd3', array_merge($this->raceFields(), ['win' => $items]));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($oddsSource, 'jb', ['kol_ods.kd3' => [$oddsRecord]]), $oddsSource);
        $this->assertDatabaseHas('race_odds', ['odds_phase' => 'forecast', 'bet_type' => 'win', 'combination_key' => '01', 'odds' => '1.5']);

        $commentSource = $this->source('mb', now()->addSecond());
        $unknownRace = $this->raceFields('20250801');
        $unknownRace['venue_code'] = '99';
        $comment = $this->record($commentSource, 'mb', 'kol_com1.kd3', array_merge($unknownRace, [
            'horse_code' => '0000301', 'horse_name' => 'コメント馬', 'connections_comment' => '合成コメント',
            'next_race_memo' => null, 'previous_comment' => null,
        ]));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($commentSource, 'mb', ['kol_com1.kd3' => [$comment]]), $commentSource);
        $this->assertDatabaseHas('race_comments', ['artifact_type' => 'mb', 'race_id' => null, 'comment_text' => '合成コメント']);
    }

    /** @param list<array{sequence:int,speed_index:?string}> $speedIndices */
    private function hbPackage(object $source, string $horseCode, array $speedIndices): Kd3ParsedPackage
    {
        $race = $this->raceFields();
        $horse = $this->horseRecord($source, 'hb', $horseCode, 'テストホース');
        $header = $this->record($source, 'hb', 'kol_den1.kd3', array_merge($race, [
            'source_category_code' => '0', 'discipline_code' => '0', 'race_name' => '合成競走', 'grade_code' => null,
            'weight_condition_code' => '03', 'age_condition_code' => '5', 'class_code' => '00004', 'surface_code' => '1',
            'course_direction_code' => '0', 'course_code' => '0', 'distance' => 1600, 'scheduled_start' => '12:30', 'runner_count' => 1,
        ]));
        $workout = [
            'sequence' => 1, 'rider' => '助手', 'training_date' => '20260901', 'place' => '栗東', 'course_code' => 'CW',
            'track_condition' => '良', 'clock_8f' => null, 'clock_7f' => null, 'clock_6f' => '82.0', 'clock_5f' => '66.0',
            'clock_4f' => '51.0', 'clock_3f' => '37.0', 'clock_1f' => '12.0', 'position_code' => '5', 'evaluation' => '一杯', 'exception_text' => null,
        ];
        $runner = $this->record($source, 'hb', 'kol_den2.kd3', array_merge($race, [
            'frame_no' => 1, 'horse_no' => 1, 'horse_code' => $horseCode, 'horse_name' => 'テストホース',
            'assigned_weight_tenths' => 550, 'jockey_code' => null, 'jockey_name' => null, 'trainer_code' => null, 'trainer_name' => null,
            'entry_mark_code' => '0', 'workouts' => [$workout], 'speed_indices' => $speedIndices,
        ]));

        return $this->package($source, 'hb', [
            'kol_uma.kd3' => [$horse], 'kol_den1.kd3' => [$header], 'kol_den2.kd3' => [$runner],
        ]);
    }

    private function horseRecord(object $source, string $artifact, string $horseCode, string $name): Kd3ParsedRecord
    {
        return $this->record($source, $artifact, 'kol_uma.kd3', [
            'horse_code' => $horseCode, 'horse_name' => $name, 'birth_year' => 2020, 'birth_month_day' => '0101',
            'color_code' => '03', 'breed_code' => '01', 'sex_code' => '0', 'trainer_code' => null, 'trainer_name' => null,
        ]);
    }

    private function resultRunner(int $horseId, string $date, string $category, string $discipline, ?string $status, ?string $time): int
    {
        $isoDate = $this->isoDate($date);
        $source = $this->source('ib', now()->addSecond(), $isoDate);
        $sourceId = (int) $source->id;
        $this->markImportedIbCoverage($date, $sourceId);
        $calendarId = $this->centralCalendar($date);
        $raceId = DB::table('races')->insertGetId([
            'race_calendar_id' => $calendarId, 'race_no' => 1, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resultId = DB::table('race_results')->insertGetId([
            'race_id' => $raceId, 'source_file_id' => $sourceId, 'source_record_number' => 1, 'result_status' => 'official',
            'source_category_code' => $category, 'discipline_code' => $discipline,
            'declared_runner_count' => 1, 'cancelled_runner_count' => in_array($status, ['34', '35'], true) ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('race_result_runners')->insertGetId([
            'race_result_id' => $resultId, 'horse_id' => $horseId, 'horse_no' => 1,
            'finish_position' => $status === null || in_array($status, ['36', '37'], true) ? 1 : null,
            'finish_status_code' => $status,
            'finish_time_tenths' => $time === null ? null : $this->timeTenths($time),
            'source_file_id' => $sourceId, 'source_record_number' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function centralCalendar(string $date): int
    {
        $venueId = (int) DB::table('venues')->where('name', '東京')->value('id');
        $isoDate = $this->isoDate($date);
        $existing = DB::table('race_calendars')->where(['venue_id' => $venueId, 'race_date' => $isoDate])->first();
        if (is_object($existing)) {
            return (int) $existing->id;
        }

        return (int) DB::table('race_calendars')->insertGetId([
            'venue_id' => $venueId, 'race_date' => $isoDate,
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function markImportedIbCoverage(string $date, int $sourceId): void
    {
        $isoDate = $this->isoDate($date);
        DB::table('kd3_artifact_statuses')->updateOrInsert(
            ['race_date' => $isoDate, 'artifact_type' => 'ib'],
            [
                'status' => 'downloaded', 'latest_source_file_id' => $sourceId,
                'last_checked_at' => now(), 'last_success_at' => now(), 'attempt_count' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
        $versions = [
            'source_file_id' => $sourceId,
            'importer_version' => (string) config('kd3.importer_version'),
            'parser_version' => (string) config('kd3.parser_version'),
            'spec_version' => (string) config('kd3.spec_version'),
            'status' => 'succeeded',
        ];
        if (! DB::table('kd3_import_runs')->where($versions)->exists()) {
            DB::table('kd3_import_runs')->insert($versions + [
                'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function isoDate(string $date): string
    {
        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    private function timeTenths(string $value): int
    {
        return ((int) $value[0] * 600) + ((int) substr($value, 1, 2) * 10) + (int) $value[3];
    }

    private function source(string $type, mixed $downloadedAt, string $raceDate = '2026-09-05'): object
    {
        $id = DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => $type, 'race_date' => $raceDate,
            'original_filename' => 'synthetic-'.$type.'.lzh', 'storage_disk' => 'local', 'storage_path' => 'synthetic-'.$type.'-'.uniqid(),
            'sha256' => hash('sha256', $type.uniqid()), 'size_bytes' => 0, 'source_url' => 'https://example.test/'.$type,
            'downloaded_at' => $downloadedAt,
        ]);

        return DB::table('source_files')->find($id);
    }

    /** @return array<string, mixed> */
    private function raceFields(string $date = '20260905'): array
    {
        return ['venue_code' => '04', 'year' => (int) substr($date, 0, 4), 'meeting_no' => '01', 'meeting_day' => '01', 'race_no' => '01', 'race_date' => $date];
    }

    /** @param array<string, mixed> $fields */
    private function record(object $source, string $artifact, string $file, array $fields): Kd3ParsedRecord
    {
        return new Kd3ParsedRecord((int) $source->id, 'synthetic.lzh', $artifact, $file, 1, $fields);
    }

    /** @param array<string, list<Kd3ParsedRecord>> $records */
    private function package(object $source, string $artifact, array $records): Kd3ParsedPackage
    {
        return new Kd3ParsedPackage((int) $source->id, 'synthetic.lzh', $artifact, array_keys($records), $records, array_sum(array_map('count', $records)));
    }
}
