from pathlib import Path
import re


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, text: str) -> None:
    Path(path).write_text(text)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 occurrence, found {count}")
    return text.replace(old, new, 1)


# Version the materially safer reference algorithm.
path = "config/kd3.php"
text = read(path)
text = replace_once(
    text,
    "'speed_reference_version' => '1.0.0'",
    "'speed_reference_version' => '1.1.0'",
    "speed reference version",
)
write(path, text)

# Importer: refresh newly interpreted source facts on same-source replay and fail closed on IB coverage gaps.
path = "app/Kd3/Domain/Kd3DomainImporter.php"
text = read(path)
text = replace_once(
    text,
    """                $this->upsertCurrent('runner_speed_indices', [
                    'race_entry_runner_id' => $runnerId,
                    'central_flat_run_back' => $slot,
                ], [
                    'speed_index' => $value,
                    'source_file_id' => $source->id,
                    'source_record_number' => $record->recordNumber,
                ], $summary);""",
    """                $this->upsertCurrent('runner_speed_indices', [
                    'race_entry_runner_id' => $runnerId,
                    'central_flat_run_back' => $slot,
                ], [
                    'speed_index' => $value,
                    'source_file_id' => $source->id,
                    'source_record_number' => $record->recordNumber,
                ], $summary, true, ['speed_index']);""",
    "same-source speed refresh",
)
text = replace_once(
    text,
    """                'declared_runner_count' => $record->fields['runner_count'],
                'cancelled_runner_count' => $record->fields['cancelled_runner_count'] ?? 0,
            ], $summary);""",
    """                'declared_runner_count' => $record->fields['runner_count'],
                'cancelled_runner_count' => $record->fields['cancelled_runner_count'] ?? 0,
            ], $summary, true, ['source_category_code', 'discipline_code']);""",
    "same-source result classification refresh",
)
text = replace_once(
    text,
    """                'source_file_id' => $source->id,
                'source_record_number' => $record->recordNumber,
            ], $summary);
            DB::table('races')->where('id', $race['race'])->where('status', '!=', 'completed')->update([""",
    """                'source_file_id' => $source->id,
                'source_record_number' => $record->recordNumber,
            ], $summary, true, ['cancellation_type_code']);
            DB::table('races')->where('id', $race['race'])->where('status', '!=', 'completed')->update([""",
    "same-source cancellation refresh",
)

resolver_pattern = re.compile(
    r"    /\*\*\n     \* Rebuild derived links from KD3 speed slots to canonical race-result runners\..*?\n    private function calculateSpeed\(int \$raceId\): void",
    re.S,
)
resolver_replacement = r'''    /**
     * Rebuild derived links from KD3 speed slots to canonical race-result runners.
     *
     * Resolution is fail-closed. A candidate is accepted only when every known central-racing
     * date from that candidate through the day before the target has the latest IB source
     * successfully imported with the current parser/importer/spec versions. This prevents a
     * missing historical result day from shifting all older central-flat slots by one.
     *
     * race_calendars is the authoritative expected-day set. Before references are treated as
     * complete, the central historical calendar itself must therefore be fully backfilled.
     */
    private function reconcileSpeedReferences(): void
    {
        DB::table('runner_speed_index_references')->delete();

        $referenceVersion = (string) config('kd3.speed_reference_version');
        $importerVersion = (string) config('kd3.importer_version');
        $parserVersion = (string) config('kd3.parser_version');
        $specVersion = (string) config('kd3.spec_version');
        $now = CarbonImmutable::now('UTC');
        DB::insert(<<<'SQL'
            WITH central_race_dates AS (
                SELECT DISTINCT calendar.race_date
                FROM race_calendars calendar
                JOIN source_identifiers venue_identifier
                  ON venue_identifier.source_system = 'kd3'
                 AND venue_identifier.entity_type = 'venue'
                 AND venue_identifier.entity_id = calendar.venue_id
                 AND venue_identifier.identifier_type = 'venue_code'
                 AND venue_identifier.identifier_value IN ('00', '01', '02', '03', '04', '05', '06', '07', '08', '09')
                WHERE calendar.status NOT IN ('cancelled', 'deleted')
            ),
            result_coverage AS (
                SELECT
                    central_date.race_date,
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM kd3_artifact_statuses artifact_status
                        JOIN source_files source
                          ON source.id = artifact_status.latest_source_file_id
                         AND source.source_system = 'kd3'
                         AND source.artifact_type = 'ib'
                         AND source.race_date = central_date.race_date
                        WHERE artifact_status.race_date = central_date.race_date
                          AND artifact_status.artifact_type = 'ib'
                          AND EXISTS (
                              SELECT 1
                              FROM kd3_import_runs import_run
                              WHERE import_run.source_file_id = source.id
                                AND import_run.status = 'succeeded'
                                AND import_run.importer_version = ?
                                AND import_run.parser_version = ?
                                AND import_run.spec_version = ?
                          )
                    ) THEN 0 ELSE 1 END AS missing
                FROM central_race_dates central_date
            ),
            coverage_boundaries AS (
                SELECT
                    race_date,
                    missing,
                    COALESCE(
                        SUM(missing) OVER (
                            ORDER BY race_date
                            ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                        ),
                        0
                    ) AS missing_before
                FROM result_coverage
            )
            INSERT INTO runner_speed_index_references
                (runner_speed_index_id, reference_race_result_runner_id, resolver_version, resolved_at, created_at, updated_at)
            SELECT
                rsi.id,
                eligible.reference_race_result_runner_id,
                ?,
                ?,
                ?,
                ?
            FROM runner_speed_indices rsi
            JOIN race_entry_runners target_runner
              ON target_runner.id = rsi.race_entry_runner_id
            JOIN race_entries target_entry
              ON target_entry.id = target_runner.race_entry_id
            JOIN races target_race
              ON target_race.id = target_entry.race_id
            JOIN race_calendars target_calendar
              ON target_calendar.id = target_race.race_calendar_id
            JOIN coverage_boundaries target_coverage
              ON target_coverage.race_date = target_calendar.race_date
            JOIN LATERAL (
                SELECT
                    ranked.reference_race_result_runner_id,
                    ranked.reference_race_date,
                    ranked.central_flat_run_back
                FROM (
                    SELECT
                        result_runner.id AS reference_race_result_runner_id,
                        result_calendar.race_date AS reference_race_date,
                        ROW_NUMBER() OVER (
                            ORDER BY result_calendar.race_date DESC, result_race.race_no DESC, result_runner.id DESC
                        ) AS central_flat_run_back
                    FROM race_result_runners result_runner
                    JOIN race_results result
                      ON result.id = result_runner.race_result_id
                    JOIN races result_race
                      ON result_race.id = result.race_id
                    JOIN race_calendars result_calendar
                      ON result_calendar.id = result_race.race_calendar_id
                    WHERE result_runner.horse_id = target_runner.horse_id
                      AND result_calendar.race_date < target_calendar.race_date
                      AND result.source_category_code = '0'
                      AND result.discipline_code = '0'
                      AND result_runner.finish_time_tenths IS NOT NULL
                      AND (
                          result_runner.finish_status_code IS NULL
                          OR result_runner.finish_status_code IN ('32', '36', '37')
                      )
                    ORDER BY result_calendar.race_date DESC, result_race.race_no DESC, result_runner.id DESC
                    LIMIT 5
                ) ranked
            ) eligible
              ON eligible.central_flat_run_back = rsi.central_flat_run_back
            JOIN coverage_boundaries reference_coverage
              ON reference_coverage.race_date = eligible.reference_race_date
             AND reference_coverage.missing = 0
             AND reference_coverage.missing_before = target_coverage.missing_before
        SQL, [
            $importerVersion,
            $parserVersion,
            $specVersion,
            $referenceVersion,
            $now,
            $now,
            $now,
        ]);
    }

    private function calculateSpeed(int $raceId): void'''
text, count = resolver_pattern.subn(resolver_replacement, text, count=1)
if count != 1:
    raise SystemExit(f"resolver function: expected 1 replacement, found {count}")
write(path, text)

# Integration tests: same-source replay refresh and fail-closed gap behavior.
path = "tests/Integration/Kd3DomainImporterTest.php"
text = read(path)
text = replace_once(
    text,
    """        $first = $importer->import($package, $source);
        $second = $importer->import($package, $source);

        $this->assertGreaterThan(0, $first->inserted);
        $this->assertGreaterThan(0, $second->unchanged);""",
    """        $first = $importer->import($package, $source);
        DB::table('runner_speed_indices')->where('central_flat_run_back', 1)->update(['speed_index' => 867]);
        $second = $importer->import($package, $source);

        $this->assertGreaterThan(0, $first->inserted);
        $this->assertGreaterThan(0, $second->updated);
        $this->assertGreaterThan(0, $second->unchanged);""",
    "same-source speed replay test",
)
text = replace_once(
    text,
    """        $resultSource = $this->source('ib', now()->addSecond());

""",
    "",
    "remove shared result source",
)
needle = "$this->resultRunner($horseId, (int) $resultSource->id, "
count = text.count(needle)
if count != 9:
    raise SystemExit(f"resultRunner call source argument: expected 9 occurrences, found {count}")
text = text.replace(needle, "$this->resultRunner($horseId, ")
text = replace_once(
    text,
    """        $eligible5 = $this->resultRunner($horseId, '20260705', '0', '0', null, '1345');

        $importer->reconcile();

        $expected = [1 => $eligible1, 2 => $eligible2, 3 => $eligible3, 4 => $eligible4, 5 => $eligible5];""",
    """        $eligible5 = $this->resultRunner($horseId, '20260705', '0', '0', null, '1345');

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

        $expected = [1 => $eligible1, 2 => $eligible2, 3 => $eligible3, 4 => $eligible4, 5 => $eligible5];""",
    "coverage gap assertion",
)
text = replace_once(
    text,
    """        $this->app->make(Kd3DomainImporter::class)->import($this->package($source, 'ib', [
            'kol_uma.kd3' => [$horse], 'kol_sei1.kd3' => [$header], 'kol_sei2.kd3' => [$runner],
        ]), $source);

        $this->assertDatabaseHas('race_result_runners', [""",
    """        $package = $this->package($source, 'ib', [
            'kol_uma.kd3' => [$horse], 'kol_sei1.kd3' => [$header], 'kol_sei2.kd3' => [$runner],
        ]);
        $importer = $this->app->make(Kd3DomainImporter::class);
        $importer->import($package, $source);
        DB::table('race_results')->update(['source_category_code' => null, 'discipline_code' => null]);
        DB::table('race_result_runners')->update(['cancellation_type_code' => null]);
        $importer->import($package, $source);

        $this->assertDatabaseHas('race_result_runners', [""",
    "same-source result replay test",
)

helper_pattern = re.compile(
    r"    private function resultRunner\(int \$horseId, int \$sourceId, string \$date, string \$category, string \$discipline, \?string \$status, \?string \$time\): int\n    \{.*?\n    \}\n\n    private function timeTenths",
    re.S,
)
helper_replacement = r'''    private function resultRunner(int $horseId, string $date, string $category, string $discipline, ?string $status, ?string $time): int
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

    private function timeTenths'''
text, count = helper_pattern.subn(helper_replacement, text, count=1)
if count != 1:
    raise SystemExit(f"resultRunner helper: expected 1 replacement, found {count}")
text = replace_once(
    text,
    "private function source(string $type, mixed $downloadedAt): object",
    "private function source(string $type, mixed $downloadedAt, string $raceDate = '2026-09-05'): object",
    "source helper signature",
)
text = replace_once(
    text,
    """            'source_system' => 'kd3', 'artifact_type' => $type, 'race_date' => '2026-09-05',""",
    """            'source_system' => 'kd3', 'artifact_type' => $type, 'race_date' => $raceDate,""",
    "source helper race date",
)
write(path, text)

# Reference design: document fail-closed coverage and replay path.
path = "docs/database/speed-index-reference.md"
text = read(path)
anchor = "未知のstatusは安全側に倒して対象外とし、誤referenceを作らない。"
replacement = """未知のstatusは安全側に倒して対象外とし、誤referenceを作らない。

## Coverage safety

候補が中央平地の有効実走であっても、候補日からtarget前日までの中央開催日にIB取込の穴が1日でもある場合、そのreferenceは作らない。途中の1走欠損によって2走前以降が1slotずつずれる事故を防ぐためである。

resolver v1.1.0 は `race_calendars` を中央開催日の期待集合として使い、各日について `kd3_artifact_statuses.latest_source_file_id` がその日の最新IBを指し、そのsourceに現行 `parser_version` / `importer_version` / `spec_version` の成功した `kd3_import_runs` があることを要求する。候補日自身もcoverage済みでなければならない。target当日のIBはまだ結果前でよいため要求しない。

この判定はfail-closedであり、coverageが不足している場合は誤ったFKを推測せずreference rowなしとする。したがって、referenceを完全なものとして扱う前提として中央の `race_calendars` 自体も全期間backfill済みである必要がある。
"""
text = replace_once(text, anchor, replacement, "coverage safety docs")
text = replace_once(
    text,
    """この変更は canonical history の意味を変えるため、開発中DBでは migration 後の部分補正より `migrate:fresh` + KD3 oldest-to-newest reimport を推奨する。""",
    """この変更では `php artisan migrate` 後、既存 `source_files` を保持したままKD3をoldest-to-newestで全量再インポートする。same-source replayでも新設classification / cancellation type / speed index正規化をrefreshするため、raw sourceの再ダウンロードは不要である。reference coverageは現行parser/importer/specの成功runだけを有効とする。`migrate:fresh` は `source_files` も消すため、raw metadataを再構築する意図がない限り必須ではない。""",
    "rebuild docs",
)
write(path, text)

# Logical design: record fail-closed coverage semantics.
path = "docs/database/logical-design.md"
text = read(path)
text = replace_once(
    text,
    """`race_results.source_category_code / discipline_code` と `race_result_runners` の完走状態・タイムから中央平地の有効実走だけを新しい順に採用する。詳細ルールは [Speed Index Reference Design](speed-index-reference.md) を参照。""",
    """`race_results.source_category_code / discipline_code` と `race_result_runners` の完走状態・タイムから中央平地の有効実走だけを新しい順に採用する。候補日からtarget前日までの中央開催日に現行versionのIB成功取込が1日でも欠ける場合はreferenceを作らない。詳細ルールは [Speed Index Reference Design](speed-index-reference.md) を参照。""",
    "logical coverage note",
)
write(path, text)

# Architecture: make replay and coverage requirements explicit.
path = "docs/architecture/kd3-domain-import.md"
text = read(path)
text = replace_once(
    text,
    """speed referenceとcommentのnullable race referenceは派生参照として再解決可能にする。speedのsource factは `runner_speed_indices` に保持し、参照先は `runner_speed_index_references` としてcanonical resultからresolver version付きで全件再構築する。commentは同一immutable sourceの再実行でも未解決raceが後からcanonical化された場合にrace referenceだけを更新する。""",
    """speed referenceとcommentのnullable race referenceは派生参照として再解決可能にする。speedのsource factは `runner_speed_indices` に保持し、参照先は `runner_speed_index_references` としてcanonical resultからresolver version付きで全件再構築する。speed referenceは候補からtarget直前までの中央開催日に現行versionのIB成功取込が連続している場合だけ作る。同一immutable sourceの再実行では、新設classification / cancellation type / speed index正規化のsource factだけを明示的にrefreshする。commentは未解決raceが後からcanonical化された場合にrace referenceだけを更新する。""",
    "architecture coverage/replay note",
)
write(path, text)

# Temporary runner removes itself and this script before the validated commit.
Path(".github/workflows/pr29-safety-run.yml").unlink(missing_ok=True)
Path(__file__).unlink()
