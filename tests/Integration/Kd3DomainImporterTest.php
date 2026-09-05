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

    public function test_hb_import_normalizes_entry_history_workouts_speed_and_is_idempotent(): void
    {
        $source = $this->source('hb', now());
        $race = $this->raceFields();
        $horse = $this->record($source, 'hb', 'kol_uma.kd3', array_merge(['horse_code' => '0000001', 'horse_name' => 'テストホース',
            'birth_year' => 2020, 'birth_month_day' => '0102', 'color_code' => '03', 'breed_code' => '01', 'sex_code' => '0',
            'trainer_code' => '00001', 'trainer_name' => 'テスト厩舎'], ['recent_histories' => [array_merge($this->raceFields('20250801'),
                ['sequence' => 1, 'horse_no' => 2, 'source_category_code' => '0', 'discipline_code' => '0', 'surface_code' => '1',
                    'finish_position' => 2, 'finish_time' => '1345', 'odds_tenths' => 25])], 'older_histories' => []]));
        $header = $this->record($source, 'hb', 'kol_den1.kd3', array_merge($race, ['race_name' => '合成競走', 'grade_code' => null,
            'weight_condition_code' => '03', 'age_condition_code' => '5', 'class_code' => '00004', 'surface_code' => '1',
            'course_direction_code' => '0', 'course_code' => '0', 'distance' => 1600, 'scheduled_start' => '12:30', 'runner_count' => 1]));
        $workout = ['sequence' => 1, 'rider' => '助手', 'training_date' => '20260901', 'place' => '栗東', 'course_code' => 'CW',
            'track_condition' => '良', 'clock_8f' => null, 'clock_7f' => null, 'clock_6f' => '82.0', 'clock_5f' => '66.0',
            'clock_4f' => '51.0', 'clock_3f' => '37.0', 'clock_1f' => '12.0', 'position_code' => '5', 'evaluation' => '一杯', 'exception_text' => null];
        $runner = $this->record($source, 'hb', 'kol_den2.kd3', array_merge($race, ['frame_no' => 1, 'horse_no' => 1, 'horse_code' => '0000001',
            'horse_name' => 'テストホース', 'sex_code' => '0', 'assigned_weight_tenths' => 550, 'jockey_code' => '00001',
            'jockey_name' => 'テスト騎手', 'trainer_code' => '00001', 'trainer_name' => 'テスト厩舎', 'entry_mark_code' => '0', 'birth_year' => 2020,
            'workouts' => [$workout], 'speed_indices' => array_map(static fn (int $sequence): array => ['sequence' => $sequence, 'speed_index' => (string) (70 + $sequence)], range(1, 5))]));
        $package = $this->package($source, 'hb', ['kol_uma.kd3' => [$horse], 'kol_den1.kd3' => [$header], 'kol_den2.kd3' => [$runner]]);

        $first = $this->app->make(Kd3DomainImporter::class)->import($package, $source);
        $second = $this->app->make(Kd3DomainImporter::class)->import($package, $source);

        $this->assertGreaterThan(0, $first->inserted);
        $this->assertGreaterThan(0, $second->unchanged);
        $this->assertDatabaseCount('race_entries', 1);
        $this->assertDatabaseCount('race_entry_runners', 1);
        $this->assertDatabaseCount('runner_workouts', 1);
        $this->assertDatabaseCount('runner_speed_indices', 5);
        $this->assertDatabaseCount('race_speed_statistics', 5);
        $this->assertDatabaseCount('horse_race_histories', 1);
        $this->assertDatabaseHas('horse_race_histories', ['mapping_status' => 'unresolved']);
        $entryQuery = DB::table('race_entries')->join('race_entry_runners', 'race_entry_runners.race_entry_id', '=', 'race_entries.id')
            ->join('horses', 'horses.id', '=', 'race_entry_runners.horse_id')->join('jockeys', 'jockeys.id', '=', 'race_entry_runners.jockey_id')
            ->join('trainers', 'trainers.id', '=', 'race_entry_runners.trainer_id')->first(['horses.name as horse', 'jockeys.name as jockey', 'trainers.name as trainer']);
        $this->assertSame('テストホース', $entryQuery->horse);
        $this->assertSame([1], DB::table('runner_speed_indices')->orderBy('central_flat_run_back')->limit(1)->pluck('actual_run_back')->all());
        $this->assertSame([2], DB::table('horse_race_histories')->orderByDesc('race_date')->pluck('horse_no')->all());
        $this->assertNotNull(DB::table('race_speed_statistics')->where('central_flat_run_back', 1)->first());
        $this->assertNotNull(DB::table('race_speed_metrics')->first());
    }

    public function test_ib_without_sei3_and_with_sei3_reuses_the_same_race(): void
    {
        $source = $this->source('ib', now());
        $race = $this->raceFields();
        $uma = $this->record($source, 'ib', 'kol_uma.kd3', ['horse_code' => '0000002', 'horse_name' => 'リザルトホース',
            'birth_year' => 2020, 'birth_month_day' => '0203', 'color_code' => '03', 'breed_code' => '01', 'sex_code' => '1',
            'trainer_code' => '00002', 'trainer_name' => '結果厩舎', 'recent_histories' => [], 'older_histories' => []]);
        $header = $this->record($source, 'ib', 'kol_sei1.kd3', array_merge($race, ['runner_count' => 1, 'cancelled_runner_count' => 0,
            'pace_code' => '1', 'weather_code' => '0', 'track_condition_code' => '0']));
        $runner = $this->record($source, 'ib', 'kol_sei2.kd3', array_merge($race, ['horse_no' => 2, 'horse_code' => '0000002',
            'horse_name' => 'リザルトホース', 'sex_code' => '1', 'assigned_weight_tenths' => 540, 'body_weight' => 450,
            'body_weight_delta' => -2, 'jockey_code' => '00002', 'jockey_name' => '結果騎手', 'trainer_code' => '00002',
            'trainer_name' => '結果厩舎', 'popularity' => 1, 'final_odds_tenths' => 25, 'finish_position' => 1,
            'finish_status_code' => null, 'finish_time' => '1345', 'margin_whole' => null, 'margin_code' => null,
            'last_3f_tenths' => 340, 'passing_1' => '02', 'passing_2' => '02', 'passing_3' => '01', 'passing_4' => '01', 'birth_year' => 2020]));
        $without = $this->package($source, 'ib', ['kol_uma.kd3' => [$uma], 'kol_sei1.kd3' => [$header], 'kol_sei2.kd3' => [$runner]]);
        $this->app->make(Kd3DomainImporter::class)->import($without, $source);
        $this->assertDatabaseCount('race_sanctions', 0);

        $newSource = $this->source('ib', now()->addSecond());
        $sanction = $this->record($newSource, 'ib', 'kol_sei3.kd3', array_merge($race, ['sanction_description' => '合成制裁内容']));
        $with = $this->package($newSource, 'ib', ['kol_uma.kd3' => [$this->withSource($uma, $newSource)],
            'kol_sei1.kd3' => [$this->withSource($header, $newSource)], 'kol_sei2.kd3' => [$this->withSource($runner, $newSource)], 'kol_sei3.kd3' => [$sanction]]);
        $this->app->make(Kd3DomainImporter::class)->import($with, $newSource);
        $this->assertDatabaseCount('races', 1);
        $this->assertDatabaseCount('race_results', 1);
        $this->assertDatabaseCount('race_result_runners', 1);
        $this->assertDatabaseCount('race_sanctions', 1);
        $resultQuery = DB::table('race_results')->join('race_result_runners', 'race_result_runners.race_result_id', '=', 'race_results.id')
            ->join('horses', 'horses.id', '=', 'race_result_runners.horse_id')->first(['horses.name', 'race_result_runners.finish_position']);
        $this->assertSame('リザルトホース', $resultQuery->name);
        $this->assertSame(1, $resultQuery->finish_position);
    }

    public function test_forecast_odds_and_unresolved_comments_are_retained(): void
    {
        $oddsSource = $this->source('jb', now());
        $items = array_map(static fn (int $sequence): array => ['sequence' => $sequence, 'odds_raw' => $sequence === 1 ? '00015' : '     '], range(1, 18));
        $oddsRecord = $this->record($oddsSource, 'jb', 'kol_ods.kd3', array_merge($this->raceFields(), ['win' => $items]));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($oddsSource, 'jb', ['kol_ods.kd3' => [$oddsRecord]]), $oddsSource);
        $this->assertDatabaseCount('race_odds', 18);
        $this->assertDatabaseHas('race_odds', ['odds_phase' => 'forecast', 'bet_type' => 'win', 'combination_key' => '01', 'odds' => '1.5']);
        $this->assertSame('1.5', DB::table('race_odds')->where(['odds_phase' => 'forecast', 'bet_type' => 'win', 'combination_key' => '01'])->value('odds'));

        $finalSource = $this->source('kd', now()->addSecond());
        $finalRecord = $this->record($finalSource, 'kd', 'kol_kod.kd3', array_merge($this->raceFields(), ['win' => $items]));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($finalSource, 'kd', ['kol_kod.kd3' => [$finalRecord]]), $finalSource);
        $this->assertDatabaseHas('race_odds', ['odds_phase' => 'final', 'bet_type' => 'win', 'combination_key' => '01', 'odds' => '1.5']);

        $commentSource = $this->source('mb', now()->addSecond());
        $unknownRace = $this->raceFields('20250801');
        $unknownRace['venue_code'] = '99';
        $comment = $this->record($commentSource, 'mb', 'kol_com1.kd3', array_merge($unknownRace, ['horse_code' => '0000003',
            'horse_name' => 'コメント馬', 'jockey_code' => null, 'jockey_name' => null, 'connections_comment' => '合成コメント',
            'next_race_memo' => null, 'previous_comment' => '前回コメント']));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($commentSource, 'mb', ['kol_com1.kd3' => [$comment]]), $commentSource);
        $this->assertDatabaseCount('race_comments', 2);
        $this->assertDatabaseHas('race_comments', ['artifact_type' => 'mb', 'race_id' => null, 'comment_type' => 'connections_comment']);

        $lbSource = $this->source('lb', now()->addSeconds(2));
        $lbComment = $this->record($lbSource, 'lb', 'kol_com1.kd3', array_merge($unknownRace, ['horse_code' => '0000003',
            'horse_name' => 'コメント馬', 'jockey_code' => null, 'jockey_name' => null, 'connections_comment' => '成績コメント',
            'next_race_memo' => null, 'previous_comment' => null]));
        $this->app->make(Kd3DomainImporter::class)->import($this->package($lbSource, 'lb', ['kol_com1.kd3' => [$lbComment]]), $lbSource);
        $this->assertDatabaseHas('race_comments', ['artifact_type' => 'lb', 'comment_type' => 'connections_comment', 'comment_text' => '成績コメント']);
    }

    public function test_older_source_cannot_roll_back_newer_canonical_odds(): void
    {
        $newer = $this->source('jb', now());
        $older = $this->source('jb', now()->subDay());
        $items = static fn (string $first): array => array_map(static fn (int $sequence): array => ['sequence' => $sequence,
            'odds_raw' => $sequence === 1 ? $first : '     '], range(1, 18));
        $newRecord = $this->record($newer, 'jb', 'kol_ods.kd3', array_merge($this->raceFields(), ['win' => $items('00025')]));
        $oldRecord = $this->record($older, 'jb', 'kol_ods.kd3', array_merge($this->raceFields(), ['win' => $items('00015')]));
        $importer = $this->app->make(Kd3DomainImporter::class);
        $importer->import($this->package($newer, 'jb', ['kol_ods.kd3' => [$newRecord]]), $newer);
        $stale = $importer->import($this->package($older, 'jb', ['kol_ods.kd3' => [$oldRecord]]), $older);

        $this->assertGreaterThan(0, $stale->skipped);
        $this->assertDatabaseHas('race_odds', ['combination_key' => '01', 'odds' => '2.5', 'source_file_id' => $newer->id]);
        $this->assertDatabaseMissing('race_odds', ['combination_key' => '01', 'odds' => '1.5', 'source_file_id' => $older->id]);
    }

    private function source(string $type, mixed $downloadedAt): object
    {
        $id = DB::table('source_files')->insertGetId(['source_system' => 'kd3', 'artifact_type' => $type, 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic-'.$type.'.lzh', 'storage_disk' => 'local', 'storage_path' => 'synthetic-'.$type.'-'.uniqid(),
            'sha256' => hash('sha256', $type.uniqid()), 'size_bytes' => 0, 'source_url' => 'https://example.test/'.$type, 'downloaded_at' => $downloadedAt]);

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

    private function withSource(Kd3ParsedRecord $record, object $source): Kd3ParsedRecord
    {
        return new Kd3ParsedRecord((int) $source->id, $record->originalFilename, $record->artifactType, $record->fileName, $record->recordNumber, $record->fields);
    }
}
