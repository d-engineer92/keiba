<?php

namespace App\Kd3\Domain;

use App\Kd3\Kd3ParsedPackage;
use App\Kd3\Kd3ParsedRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Kd3DomainImporter
{
    /** @var array<int, object> */
    private array $sources = [];

    public function __construct(private readonly EntityResolver $entities, private readonly RaceResolver $races, private readonly OddsNormalizer $odds, private readonly SpeedCalculator $speeds) {}

    public function import(Kd3ParsedPackage $package, object $source, bool $reconcile = true): ImportSummary
    {
        $summary = new ImportSummary;
        DB::transaction(function () use ($package, $source, $summary, $reconcile): void {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('kd3-domain-import'))");
            $horses = $this->importHorses($package, $source, $summary);
            match ($package->artifactType) {
                'hb' => $this->importEntries($package, $source, $horses, $summary),
                'ib' => $this->importResults($package, $source, $horses, $summary),
                'jb', 'kd' => $this->importOdds($package, $source, $summary),
                'lb', 'mb' => $this->importComments($package, $source, $summary),
                default => throw new Kd3ImportException('Unsupported artifact type.', 'mapping', 'artifact', $package->artifactType),
            };
            if ($reconcile) {
                $this->reconcileHistories();
                $this->reconcileSpeedReferences();
            }
        }, 3);

        return $summary;
    }

    public function reconcile(): void
    {
        DB::transaction(function (): void {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('kd3-domain-import'))");
            $this->reconcileHistories();
            $this->reconcileSpeedReferences();
        }, 3);
    }

    /** @return array<string, int> */
    private function importHorses(Kd3ParsedPackage $package, object $source, ImportSummary $summary): array
    {
        $ids = [];
        foreach ($package->records['kol_uma.kd3'] ?? [] as $record) {
            $fields = $record->fields;
            $horseId = $this->entities->resolve('horse', 'horse_code', (string) $fields['horse_code'], $this->text($fields['horse_name'] ?? null));
            $ids[(string) $fields['horse_code']] = $horseId;
            $trainerId = $fields['trainer_code'] === null ? null : $this->entities->resolve('trainer', 'trainer_code', (string) $fields['trainer_code'], $this->text($fields['trainer_name'] ?? null));
            $snapshot = $package->artifactType === 'hb' ? 'horse_entry_snapshots' : ($package->artifactType === 'ib' ? 'horse_result_snapshots' : null);
            if ($snapshot !== null) {
                $this->upsertSnapshot($snapshot, $horseId, $record, ['horse_name' => $this->text($fields['horse_name'] ?? null),
                    'sex_code' => $fields['sex_code'], 'birth_date' => $this->birthDate($fields), 'color_code' => $fields['color_code'],
                    'breed_code' => $fields['breed_code'], 'trainer_id' => $trainerId], $summary);
            }
            foreach ([...($fields['recent_histories'] ?? []), ...($fields['older_histories'] ?? [])] as $history) {
                $this->upsertHistory($horseId, $history, (int) $source->id, $summary);
            }
        }

        return $ids;
    }

    /** @param array<string, int> $horseIds */
    private function importEntries(Kd3ParsedPackage $package, object $source, array $horseIds, ImportSummary $summary): void
    {
        $entries = [];
        foreach ($package->records['kol_den1.kd3'] as $record) {
            $raceId = $this->requiredRace($record->fields);
            $disposition = $this->aggregateDisposition('race_entries', ['race_id' => $raceId], (int) $source->id);
            $start = $this->scheduledStart((string) $record->fields['race_date'], $record->fields['scheduled_start']);
            $entryId = $this->upsertCurrent('race_entries', ['race_id' => $raceId], [
                'source_file_id' => $source->id, 'source_record_number' => $record->recordNumber, 'race_name' => $this->text($record->fields['race_name']),
                'scheduled_start_at' => $start, 'surface_code' => $record->fields['surface_code'], 'course_direction_code' => $record->fields['course_direction_code'],
                'course_code' => $record->fields['course_code'], 'distance' => $record->fields['distance'], 'grade_code' => $record->fields['grade_code'],
                'age_condition_code' => $record->fields['age_condition_code'], 'class_code' => $record->fields['class_code'],
                'weight_condition_code' => $record->fields['weight_condition_code'], 'declared_runner_count' => $record->fields['runner_count'],
            ], $summary);
            $entries[RaceKey::from($record->fields)] = ['entry' => $entryId, 'race' => $raceId, 'disposition' => $disposition];
            $raceUpdates = [];
            $race = DB::table('races')->find($raceId);
            if ($disposition !== 'stale' && is_object($race) && $race->name === null && $this->text($record->fields['race_name']) !== null) {
                $raceUpdates['name'] = $this->text($record->fields['race_name']);
            }
            if ($disposition !== 'stale' && is_object($race) && $race->scheduled_start_at === null && $start !== null) {
                $raceUpdates['scheduled_start_at'] = $start;
            }
            if ($raceUpdates !== []) {
                $raceUpdates['updated_at'] = CarbonImmutable::now('UTC');
                DB::table('races')->where('id', $raceId)->update($raceUpdates);
            }
        }
        $runners = [];
        $incomingHorseIds = [];
        foreach ($package->records['kol_den2.kd3'] as $record) {
            $race = $entries[RaceKey::from($record->fields)] ?? null;
            if ($race === null) {
                throw new Kd3ImportException('Runner has no resolved entry.', 'reconciliation', 'race_entry_runner', RaceKey::from($record->fields));
            }
            if ($race['disposition'] === 'stale') {
                $summary->skipped++;

                continue;
            }
            $horseId = $horseIds[(string) $record->fields['horse_code']] ?? $this->entities->resolve('horse', 'horse_code', (string) $record->fields['horse_code'], $this->text($record->fields['horse_name']));
            $runners[] = ['record' => $record, 'race' => $race, 'horse' => $horseId];
            $incomingHorseIds[$race['entry']][] = $horseId;
        }
        foreach ($entries as $entry) {
            if ($entry['disposition'] === 'newer') {
                $this->reconcileEntryRunners((int) $entry['entry'], $incomingHorseIds[$entry['entry']] ?? [], $summary);
            }
        }
        $touched = [];
        foreach ($entries as $entry) {
            if (in_array($entry['disposition'], ['new', 'newer'], true)) {
                $touched[$entry['race']] = true;
            }
        }
        foreach ($runners as $runner) {
            /** @var Kd3ParsedRecord $record */
            $record = $runner['record'];
            $race = $runner['race'];
            $horseId = (int) $runner['horse'];
            $jockeyId = $record->fields['jockey_code'] === null ? null : $this->entities->resolve('jockey', 'jockey_code', (string) $record->fields['jockey_code'], $this->text($record->fields['jockey_name']));
            $trainerId = $record->fields['trainer_code'] === null ? null : $this->entities->resolve('trainer', 'trainer_code', (string) $record->fields['trainer_code'], $this->text($record->fields['trainer_name']));
            $runnerId = $this->upsertCurrent('race_entry_runners', ['race_entry_id' => $race['entry'], 'horse_id' => $horseId], [
                'horse_no' => $record->fields['horse_no'], 'frame_no' => $record->fields['frame_no'], 'jockey_id' => $jockeyId, 'trainer_id' => $trainerId,
                'assigned_weight' => $this->tenths($record->fields['assigned_weight_tenths']), 'entry_mark_code' => $record->fields['entry_mark_code'],
                'source_file_id' => $source->id, 'source_record_number' => $record->recordNumber,
            ], $summary);
            foreach ($record->fields['workouts'] as $workout) {
                if ($this->allBlank($workout, ['rider', 'training_date', 'place', 'course_code', 'exception_text'])) {
                    continue;
                }
                $this->upsertCurrent('runner_workouts', ['race_entry_runner_id' => $runnerId, 'sequence_no' => $workout['sequence']],
                    array_merge(array_intersect_key($workout, array_flip(['training_date', 'rider', 'place', 'course_code', 'track_condition', 'clock_8f', 'clock_7f', 'clock_6f', 'clock_5f', 'clock_4f', 'clock_3f', 'clock_1f', 'position_code', 'evaluation', 'exception_text'])),
                        ['source_file_id' => $source->id, 'source_record_number' => $record->recordNumber]), $summary);
            }
            if ($race['disposition'] === 'newer') {
                $workoutSequences = array_map(static fn (array $workout): int => (int) $workout['sequence'], array_filter(
                    $record->fields['workouts'], fn (array $workout): bool => ! $this->allBlank($workout, ['rider', 'training_date', 'place', 'course_code', 'exception_text'])
                ));
                $this->deleteMissingRows('runner_workouts', 'race_entry_runner_id', $runnerId, 'sequence_no', $workoutSequences, $summary);
            }
            $histories = DB::table('horse_race_histories')->where('horse_id', $horseId)->where('race_date', '<', $this->date((string) $record->fields['race_date']))->orderByDesc('race_date')->get();
            $central = $histories->filter(static fn (object $row): bool => $row->source_category_code === '0' && $row->discipline_code === '0')->values();
            foreach ($record->fields['speed_indices'] as $speed) {
                $slot = 6 - (int) $speed['sequence'];
                $reference = $central->get($slot - 1);
                $actual = $reference === null ? null : $histories->search(static fn (object $row): bool => $row->id === $reference->id) + 1;
                $this->upsertCurrent('runner_speed_indices', ['race_entry_runner_id' => $runnerId, 'central_flat_run_back' => $slot], [
                    'target_race_id' => $race['race'], 'horse_id' => $horseId, 'speed_index' => $speed['speed_index'] === null ? null : (float) $speed['speed_index'],
                    'reference_race_id' => $reference?->reference_race_id, 'actual_run_back' => $actual,
                    'mapping_status' => $reference?->mapping_status === 'exact' ? 'exact' : 'unresolved',
                    'source_file_id' => $source->id, 'source_record_number' => $record->recordNumber,
                ], $summary);
            }
            if ($race['disposition'] === 'newer') {
                $speedSlots = array_map(static fn (array $speed): int => 6 - (int) $speed['sequence'], $record->fields['speed_indices']);
                $this->deleteMissingSpeedIndices($runnerId, $speedSlots, $summary);
            }
            $touched[$race['race']] = true;
        }
        foreach (array_keys($touched) as $raceId) {
            $this->calculateSpeed((int) $raceId);
        }
    }

    /** @param array<string, int> $horseIds */
    private function importResults(Kd3ParsedPackage $package, object $source, array $horseIds, ImportSummary $summary): void
    {
        $results = [];
        foreach ($package->records['kol_sei1.kd3'] as $record) {
            $raceId = $this->requiredRace($record->fields);
            $disposition = $this->aggregateDisposition('race_results', ['race_id' => $raceId], (int) $source->id);
            $resultId = $this->upsertCurrent('race_results', ['race_id' => $raceId], ['source_file_id' => $source->id,
                'source_record_number' => $record->recordNumber, 'result_status' => 'official', 'weather_code' => $record->fields['weather_code'],
                'track_condition_code' => $record->fields['track_condition_code'], 'pace_code' => $record->fields['pace_code'],
                'declared_runner_count' => $record->fields['runner_count'], 'cancelled_runner_count' => $record->fields['cancelled_runner_count'] ?? 0], $summary);
            $results[RaceKey::from($record->fields)] = ['result' => $resultId, 'race' => $raceId, 'disposition' => $disposition];
        }
        $runners = [];
        $incomingHorseIds = [];
        foreach ($package->records['kol_sei2.kd3'] as $record) {
            $race = $results[RaceKey::from($record->fields)] ?? null;
            if ($race === null) {
                throw new Kd3ImportException('Runner has no resolved result.', 'reconciliation', 'race_result_runner', RaceKey::from($record->fields));
            }
            if ($race['disposition'] === 'stale') {
                $summary->skipped++;

                continue;
            }
            $horseId = $horseIds[(string) $record->fields['horse_code']] ?? $this->entities->resolve('horse', 'horse_code', (string) $record->fields['horse_code'], $this->text($record->fields['horse_name']));
            $runners[] = ['record' => $record, 'race' => $race, 'horse' => $horseId];
            $incomingHorseIds[$race['result']][] = $horseId;
        }
        foreach ($results as $result) {
            if ($result['disposition'] === 'newer') {
                $this->deleteMissingRows('race_result_runners', 'race_result_id', (int) $result['result'], 'horse_id', $incomingHorseIds[$result['result']] ?? [], $summary);
            }
        }
        foreach ($runners as $runner) {
            /** @var Kd3ParsedRecord $record */
            $record = $runner['record'];
            $race = $runner['race'];
            $horseId = (int) $runner['horse'];
            $jockeyId = $record->fields['jockey_code'] === null ? null : $this->entities->resolve('jockey', 'jockey_code', (string) $record->fields['jockey_code'], $this->text($record->fields['jockey_name']));
            $trainerId = $record->fields['trainer_code'] === null ? null : $this->entities->resolve('trainer', 'trainer_code', (string) $record->fields['trainer_code'], $this->text($record->fields['trainer_name']));
            $passing = array_filter(array_map(fn (string $key): mixed => $record->fields[$key], ['passing_1', 'passing_2', 'passing_3', 'passing_4']), static fn (mixed $value): bool => $value !== null);
            $this->upsertCurrent('race_result_runners', ['race_result_id' => $race['result'], 'horse_id' => $horseId], [
                'horse_no' => $record->fields['horse_no'], 'jockey_id' => $jockeyId, 'trainer_id' => $trainerId,
                'finish_position' => $this->positiveOrNull($record->fields['finish_position']), 'finish_status_code' => $record->fields['finish_status_code'],
                'finish_time_tenths' => $this->raceTimeTenths($record->fields['finish_time']), 'margin' => trim(implode('', array_filter([$record->fields['margin_whole'], $record->fields['margin_code']], static fn ($v) => $v !== null))),
                'passing_order' => $passing === [] ? null : implode('-', $passing), 'last_3f' => $this->tenths($record->fields['last_3f_tenths']),
                'body_weight' => $record->fields['body_weight'], 'body_weight_delta' => $record->fields['body_weight_delta'],
                'final_odds' => $this->tenths($record->fields['final_odds_tenths']), 'popularity' => $this->positiveOrNull($record->fields['popularity']),
                'source_file_id' => $source->id, 'source_record_number' => $record->recordNumber,
            ], $summary);
            DB::table('races')->where('id', $race['race'])->where('status', '!=', 'completed')->update(['status' => 'completed', 'updated_at' => CarbonImmutable::now('UTC')]);
        }
        foreach ($package->records['kol_sei3.kd3'] ?? [] as $record) {
            $result = $results[RaceKey::from($record->fields)] ?? null;
            if ($result !== null && $result['disposition'] === 'stale') {
                $summary->skipped++;

                continue;
            }
            $description = $this->text($record->fields['sanction_description']);
            if ($description === null) {
                continue;
            }
            $this->upsertSnapshot('race_sanctions', 0, $record, ['race_id' => $this->requiredRace($record->fields), 'horse_id' => null,
                'jockey_id' => null, 'category_code' => null, 'description' => $description], $summary, false);
        }
    }

    private function importOdds(Kd3ParsedPackage $package, object $source, ImportSummary $summary): void
    {
        $phase = $package->artifactType === 'jb' ? 'forecast' : 'final';
        foreach ($package->records as $records) {
            foreach ($records as $record) {
                $raceId = $this->requiredRace($record->fields);
                foreach ($record->fields as $market => $items) {
                    if (! is_array($items) || $items === []) {
                        continue;
                    }
                    $combinations = $this->odds->combinations($market);
                    if (count($combinations) !== count($items)) {
                        continue;
                    }
                    $rows = [];
                    foreach ($items as $index => $item) {
                        $selections = $combinations[$index];
                        $range = in_array($market, ['place', 'wide'], true);
                        $value = $this->odds->value((string) $item['odds_raw'], $range);
                        $rows[] = array_merge(['race_id' => $raceId, 'odds_phase' => $phase, 'bet_type' => $market,
                            'combination_key' => $this->odds->key($selections), 'source_file_id' => $source->id,
                            'selection_1' => $selections[0] ?? null, 'selection_2' => $selections[1] ?? null, 'selection_3' => $selections[2] ?? null,
                            'popularity' => null], $value);
                    }
                    $this->upsertOddsMarket($raceId, $phase, $market, (int) $source->id, $rows, $summary);
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertOddsMarket(int $raceId, string $phase, string $market, int $sourceId, array $rows, ImportSummary $summary): void
    {
        $existing = DB::table('race_odds')->where(['race_id' => $raceId, 'odds_phase' => $phase, 'bet_type' => $market])->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            $existingSource = (int) $existing->first()->source_file_id;
            if ($existingSource === $sourceId) {
                $summary->unchanged += count($rows);

                return;
            }
            if (! $this->isNewer($sourceId, $existingSource)) {
                $summary->skipped += count($rows);

                return;
            }
        }
        if ($existing->isNotEmpty()) {
            $incomingKeys = array_column($rows, 'combination_key');
            $deleted = DB::table('race_odds')->where(['race_id' => $raceId, 'odds_phase' => $phase, 'bet_type' => $market])
                ->when($incomingKeys !== [], static fn ($query) => $query->whereNotIn('combination_key', $incomingKeys))->delete();
            $summary->updated += $deleted;
        }
        $now = CarbonImmutable::now('UTC');
        $rows = array_map(static fn (array $row): array => array_merge($row, ['created_at' => $now, 'updated_at' => $now]), $rows);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('race_odds')->upsert($chunk, ['race_id', 'odds_phase', 'bet_type', 'combination_key'],
                ['source_file_id', 'selection_1', 'selection_2', 'selection_3', 'odds', 'odds_min', 'odds_max', 'popularity', 'status', 'updated_at']);
        }
        $updated = min($existing->count(), count($rows));
        $summary->updated += $updated;
        $summary->inserted += count($rows) - $updated;
    }

    private function importComments(Kd3ParsedPackage $package, object $source, ImportSummary $summary): void
    {
        foreach ($package->records['kol_com1.kd3'] as $record) {
            $horseId = $this->entities->resolve('horse', 'horse_code', (string) $record->fields['horse_code'], $this->text($record->fields['horse_name']));
            $raceId = $this->races->resolve($record->fields, false);
            foreach (['connections_comment', 'next_race_memo', 'previous_comment'] as $type) {
                $text = $this->text($record->fields[$type]);
                if ($text === null) {
                    $summary->skipped++;

                    continue;
                }
                $this->upsertCurrent('race_comments', ['source_file_id' => $source->id, 'source_record_number' => $record->recordNumber,
                    'comment_type' => $type], ['artifact_type' => $package->artifactType, 'race_id' => $raceId, 'horse_id' => $horseId,
                        'comment_text' => $text], $summary, false, ['race_id']);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function upsertSnapshot(string $table, int $horseId, Kd3ParsedRecord $record, array $values, ImportSummary $summary, bool $horseKey = true): int
    {
        $key = ['source_file_id' => $record->sourceFileId, 'source_record_number' => $record->recordNumber];
        if ($horseKey) {
            $key = ['source_file_id' => $record->sourceFileId, 'horse_id' => $horseId];
            $values['source_record_number'] = $record->recordNumber;
        }

        return $this->upsertCurrent($table, $key, $values, $summary, false);
    }

    /** @param array<string, mixed> $history */
    private function upsertHistory(int $horseId, array $history, int $sourceId, ImportSummary $summary): void
    {
        $reference = $this->races->resolve($history, false);
        $this->upsertCurrent('horse_race_histories', ['horse_id' => $horseId, 'history_key' => RaceKey::history($history)], [
            'race_date' => $this->date((string) $history['race_date']), 'venue_code' => $history['venue_code'],
            'meeting_no' => $history['meeting_no'], 'meeting_day' => $history['meeting_day'], 'race_no' => $history['race_no'],
            'source_category_code' => $history['source_category_code'] ?? null, 'discipline_code' => $history['discipline_code'] ?? null,
            'surface_code' => $history['surface_code'] ?? null, 'reference_race_id' => $reference, 'mapping_status' => $reference === null ? 'unresolved' : 'exact',
            'horse_no' => $history['horse_no'] ?? null, 'finish_position' => $this->positiveOrNull($history['finish_position'] ?? null),
            'finish_time_tenths' => $this->raceTimeTenths($history['finish_time'] ?? null), 'odds' => $this->tenths($history['odds_tenths'] ?? null),
            'source_file_id' => $sourceId,
        ], $summary);
    }

    private function reconcileHistories(): void
    {
        foreach (DB::table('horse_race_histories')->where('mapping_status', 'unresolved')->get() as $row) {
            $fields = ['venue_code' => $row->venue_code, 'year' => substr((string) $row->race_date, 0, 4), 'meeting_no' => $row->meeting_no,
                'meeting_day' => $row->meeting_day, 'race_no' => $row->race_no, 'race_date' => str_replace('-', '', (string) $row->race_date)];
            $raceId = $this->races->resolve($fields, false);
            if ($raceId !== null) {
                DB::table('horse_race_histories')->where('id', $row->id)->update(['reference_race_id' => $raceId, 'mapping_status' => 'exact', 'updated_at' => CarbonImmutable::now('UTC')]);
            }
        }
    }

    private function reconcileSpeedReferences(): void
    {
        $groups = DB::table('runner_speed_indices')->select(['target_race_id', 'horse_id'])->distinct()->get();
        foreach ($groups as $group) {
            $targetDate = DB::table('races')->join('race_calendars', 'race_calendars.id', '=', 'races.race_calendar_id')
                ->where('races.id', $group->target_race_id)->value('race_calendars.race_date');
            if ($targetDate === null) {
                continue;
            }
            $histories = DB::table('horse_race_histories')->where('horse_id', $group->horse_id)->where('race_date', '<', $targetDate)->orderByDesc('race_date')->get();
            $central = $histories->filter(static fn (object $row): bool => $row->source_category_code === '0' && $row->discipline_code === '0')->values();
            foreach (DB::table('runner_speed_indices')->where(['target_race_id' => $group->target_race_id, 'horse_id' => $group->horse_id])->get() as $speed) {
                $reference = $central->get((int) $speed->central_flat_run_back - 1);
                $position = $reference === null ? false : $histories->search(static fn (object $row): bool => $row->id === $reference->id);
                $values = ['reference_race_id' => $reference?->reference_race_id, 'actual_run_back' => $position === false ? null : $position + 1,
                    'mapping_status' => $reference?->mapping_status === 'exact' ? 'exact' : 'unresolved'];
                if ((int) ($speed->reference_race_id ?? 0) !== (int) ($values['reference_race_id'] ?? 0)
                    || (int) ($speed->actual_run_back ?? 0) !== (int) ($values['actual_run_back'] ?? 0)
                    || $speed->mapping_status !== $values['mapping_status']) {
                    DB::table('runner_speed_indices')->where('id', $speed->id)->update($values + ['updated_at' => CarbonImmutable::now('UTC')]);
                }
            }
        }
    }

    private function calculateSpeed(int $raceId): void
    {
        $version = (string) config('kd3.speed_calculation_version');
        for ($slot = 1; $slot <= 5; $slot++) {
            $rows = DB::table('runner_speed_indices')->where(['target_race_id' => $raceId, 'central_flat_run_back' => $slot])->whereNotNull('speed_index')->orderBy('speed_index')->get();
            $validIds = $rows->pluck('id')->map('intval')->all();
            $allIds = DB::table('runner_speed_indices')->where(['target_race_id' => $raceId, 'central_flat_run_back' => $slot])->pluck('id')->map('intval')->all();
            $invalidIds = array_values(array_diff($allIds, $validIds));
            if ($invalidIds !== []) {
                DB::table('race_speed_metrics')->whereIn('runner_speed_index_id', $invalidIds)->where('calculation_version', $version)->delete();
            }
            $calculation = $this->speeds->calculate($rows->pluck('speed_index')->map('floatval')->all());
            DB::table('race_speed_statistics')->upsert([array_merge(array_diff_key($calculation, ['metrics' => true]), ['race_id' => $raceId,
                'central_flat_run_back' => $slot, 'calculation_version' => $version, 'calculated_at' => CarbonImmutable::now('UTC')])],
                ['race_id', 'central_flat_run_back', 'calculation_version'], ['valid_count', 'excluded_count', 'mean', 'median', 'stddev', 'min', 'max', 'mad', 'calculated_at']);
            foreach ($rows as $index => $row) {
                $metric = $calculation['metrics'][$index];
                DB::table('race_speed_metrics')->upsert([['runner_speed_index_id' => $row->id, 'speed_rank' => $metric['rank'],
                    'percentile' => $metric['percentile'], 'zscore' => $metric['zscore'], 'deviation_score' => $metric['deviation_score'],
                    'robust_zscore' => $metric['robust_zscore'], 'robust_deviation_score' => $metric['robust_deviation_score'],
                    'is_outlier' => null, 'outlier_rule_version' => null, 'calculation_version' => $version,
                    'created_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC')]],
                    ['runner_speed_index_id', 'calculation_version'], ['speed_rank', 'percentile', 'zscore', 'deviation_score', 'robust_zscore', 'robust_deviation_score', 'updated_at']);
            }
        }
    }

    /** @param array<string, mixed> $key @param array<string, mixed> $values */
    private function upsertCurrent(string $table, array $key, array $values, ImportSummary $summary, bool $protectStale = true, array $refreshColumns = []): int
    {
        $query = DB::table($table);
        foreach ($key as $column => $value) {
            $query->where($column, $value);
        }
        $existing = $query->lockForUpdate()->first();
        $now = CarbonImmutable::now('UTC');
        if ($existing === null) {
            $summary->inserted++;

            return (int) DB::table($table)->insertGetId(array_merge($key, $values, ['created_at' => $now, 'updated_at' => $now]));
        }
        $incomingSource = $values['source_file_id'] ?? $key['source_file_id'] ?? null;
        $existingSource = $existing->source_file_id ?? null;
        if ($protectStale && $incomingSource !== null && $existingSource !== null) {
            if ((int) $incomingSource === (int) $existingSource) {
                if ($this->refreshDerivedColumns($table, $existing, $values, $refreshColumns)) {
                    $summary->updated++;

                    return (int) $existing->id;
                }
                $summary->unchanged++;

                return (int) $existing->id;
            }
            if (! $this->isNewer((int) $incomingSource, (int) $existingSource)) {
                $summary->skipped++;

                return (int) $existing->id;
            }
        } elseif ($incomingSource !== null && (int) $incomingSource === (int) $existingSource) {
            if ($this->refreshDerivedColumns($table, $existing, $values, $refreshColumns)) {
                $summary->updated++;

                return (int) $existing->id;
            }
            $summary->unchanged++;

            return (int) $existing->id;
        }
        $summary->updated++;
        DB::table($table)->where('id', $existing->id)->update(array_merge($values, ['updated_at' => $now]));

        return (int) $existing->id;
    }

    /** @param array<string, mixed> $key */
    private function aggregateDisposition(string $table, array $key, int $sourceId): string
    {
        $query = DB::table($table);
        foreach ($key as $column => $value) {
            $query->where($column, $value);
        }
        $existing = $query->lockForUpdate()->first();
        if ($existing === null) {
            return 'new';
        }
        if ((int) $existing->source_file_id === $sourceId) {
            return 'same';
        }

        return $this->isNewer($sourceId, (int) $existing->source_file_id) ? 'newer' : 'stale';
    }

    /** @param list<int> $incomingHorseIds */
    private function reconcileEntryRunners(int $entryId, array $incomingHorseIds, ImportSummary $summary): void
    {
        $query = DB::table('race_entry_runners')->where('race_entry_id', $entryId);
        if ($incomingHorseIds !== []) {
            $query->whereNotIn('horse_id', array_values(array_unique($incomingHorseIds)));
        }
        $runnerIds = $query->pluck('id')->map('intval')->all();
        if ($runnerIds === []) {
            return;
        }
        $speedIds = DB::table('runner_speed_indices')->whereIn('race_entry_runner_id', $runnerIds)->pluck('id')->map('intval')->all();
        if ($speedIds !== []) {
            $summary->updated += DB::table('race_speed_metrics')->whereIn('runner_speed_index_id', $speedIds)->delete();
        }
        $summary->updated += DB::table('runner_speed_indices')->whereIn('race_entry_runner_id', $runnerIds)->delete();
        $summary->updated += DB::table('runner_workouts')->whereIn('race_entry_runner_id', $runnerIds)->delete();
        $summary->updated += DB::table('race_entry_runners')->whereIn('id', $runnerIds)->delete();
    }

    /** @param list<int> $incomingValues */
    private function deleteMissingRows(string $table, string $parentColumn, int $parentId, string $naturalColumn, array $incomingValues, ImportSummary $summary): void
    {
        $query = DB::table($table)->where($parentColumn, $parentId);
        if ($incomingValues !== []) {
            $query->whereNotIn($naturalColumn, array_values(array_unique($incomingValues)));
        }
        $summary->updated += $query->delete();
    }

    /** @param list<int> $incomingSlots */
    private function deleteMissingSpeedIndices(int $runnerId, array $incomingSlots, ImportSummary $summary): void
    {
        $query = DB::table('runner_speed_indices')->where('race_entry_runner_id', $runnerId);
        if ($incomingSlots !== []) {
            $query->whereNotIn('central_flat_run_back', array_values(array_unique($incomingSlots)));
        }
        $ids = $query->pluck('id')->map('intval')->all();
        if ($ids === []) {
            return;
        }
        $summary->updated += DB::table('race_speed_metrics')->whereIn('runner_speed_index_id', $ids)->delete();
        $summary->updated += DB::table('runner_speed_indices')->whereIn('id', $ids)->delete();
    }

    /** @param array<string, mixed> $values @param list<string> $columns */
    private function refreshDerivedColumns(string $table, object $existing, array $values, array $columns): bool
    {
        $updates = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $values) && $existing->{$column} !== $values[$column]) {
                $updates[$column] = $values[$column];
            }
        }
        if ($updates === []) {
            return false;
        }
        DB::table($table)->where('id', $existing->id)->update($updates + ['updated_at' => CarbonImmutable::now('UTC')]);

        return true;
    }

    private function isNewer(int $incoming, int $existing): bool
    {
        foreach ([$incoming, $existing] as $id) {
            if (! isset($this->sources[$id])) {
                $source = DB::table('source_files')->find($id);
                if (! is_object($source)) {
                    throw new Kd3ImportException('Source lineage is missing.', 'integrity', 'source_file', (string) $id);
                }
                $this->sources[$id] = $source;
            }
        }
        $left = [(string) $this->sources[$incoming]->downloaded_at, $incoming];
        $right = [(string) $this->sources[$existing]->downloaded_at, $existing];

        return $left > $right;
    }

    /** @param array<string, mixed> $fields */
    private function requiredRace(array $fields): int
    {
        return $this->races->resolve($fields, true) ?? throw new Kd3ImportException('Venue or race could not be resolved.', 'mapping', 'race', RaceKey::from($fields));
    }

    private function scheduledStart(string $date, mixed $time): ?CarbonImmutable
    {
        if (! is_string($time) || preg_match('/^[0-9]{2}:[0-9]{2}$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Ymd H:i', $date.' '.$time, 'Asia/Tokyo') ?: null;
    }

    /** @param array<string, mixed> $fields */
    private function birthDate(array $fields): ?string
    {
        if (! is_int($fields['birth_year'] ?? null) || ! is_string($fields['birth_month_day'] ?? null)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Ymd', $fields['birth_year'].$fields['birth_month_day'], 'UTC');

        return $date === null ? null : $date->format('Y-m-d');
    }

    private function date(string $date): string
    {
        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function tenths(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : ((float) $value) / 10;
    }

    private function positiveOrNull(mixed $value): ?int
    {
        return $value === null || (int) $value <= 0 ? null : (int) $value;
    }

    private function raceTimeTenths(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^[0-9]{4}$/', $value) !== 1) {
            return null;
        }

        return ((int) $value[0] * 600) + ((int) substr($value, 1, 2) * 10) + (int) $value[3];
    }

    /** @param array<string, mixed> $values @param list<string> $keys */
    private function allBlank(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (($values[$key] ?? null) !== null && trim((string) $values[$key]) !== '') {
                return false;
            }
        }

        return true;
    }
}
