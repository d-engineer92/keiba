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


# Migration: KD3 classification belongs to the source-specific result row, not canonical races.
path = "database/migrations/2026_09_06_000009_refactor_kd3_speed_references.php"
text = read(path)
text = replace_once(
    text,
    """        Schema::table('races', function (Blueprint $table) {
            $table->string('source_category_code', 1)->nullable()->after('race_no');
            $table->string('discipline_code', 1)->nullable()->after('source_category_code');
        });""",
    """        Schema::table('race_results', function (Blueprint $table) {
            $table->string('source_category_code', 1)->nullable()->after('result_status');
            $table->string('discipline_code', 1)->nullable()->after('source_category_code');
        });""",
    "migration up classification owner",
)
text = replace_once(
    text,
    """        Schema::table('races', function (Blueprint $table) {
            $table->dropColumn(['source_category_code', 'discipline_code']);
        });""",
    """        Schema::table('race_results', function (Blueprint $table) {
            $table->dropColumn(['source_category_code', 'discipline_code']);
        });""",
    "migration down classification owner",
)
write(path, text)

# Importer: persist result classification with the result source and resolve from that row.
path = "app/Kd3/Domain/Kd3DomainImporter.php"
text = read(path)
text = replace_once(
    text,
    """                $this->mergeRaceFacts(
                    $raceId,
                    $record->fields,
                    $this->text($record->fields['race_name'] ?? null),
                    $start,
                );""",
    """                $this->mergeRaceFacts(
                    $raceId,
                    $this->text($record->fields['race_name'] ?? null),
                    $start,
                );""",
    "entry canonical facts",
)
text = replace_once(
    text,
    """                'result_status' => 'official',
                'weather_code' => $record->fields['weather_code'] ?? null,""",
    """                'result_status' => 'official',
                'source_category_code' => $record->fields['source_category_code'] ?? null,
                'discipline_code' => $record->fields['discipline_code'] ?? null,
                'weather_code' => $record->fields['weather_code'] ?? null,""",
    "result classification values",
)
text = replace_once(
    text,
    """
            if ($disposition !== 'stale') {
                $this->mergeRaceFacts($raceId, $record->fields);
            }
""",
    "\n",
    "remove result-to-race classification merge",
)
if text.count("result_race.source_category_code = '0'") != 1:
    raise SystemExit("resolver category predicate not found exactly once")
if text.count("result_race.discipline_code = '0'") != 1:
    raise SystemExit("resolver discipline predicate not found exactly once")
text = text.replace("result_race.source_category_code = '0'", "result.source_category_code = '0'", 1)
text = text.replace("result_race.discipline_code = '0'", "result.discipline_code = '0'", 1)
text = replace_once(
    text,
    "private function mergeRaceFacts(int $raceId, array $fields, ?string $name = null, ?CarbonImmutable $start = null): void",
    "private function mergeRaceFacts(int $raceId, ?string $name = null, ?CarbonImmutable $start = null): void",
    "mergeRaceFacts signature",
)
pattern = re.compile(
    r"        \$updates = \[\];\n        foreach \(\['source_category_code', 'discipline_code'\] as \$column\) \{.*?\n        \}\n\n        if \(\(\$race->name",
    re.S,
)
text, count = pattern.subn("        $updates = [];\n\n        if (($race->name", text, count=1)
if count != 1:
    raise SystemExit(f"mergeRaceFacts classification block: expected 1 occurrence, found {count}")
write(path, text)

# Schema tests.
path = "tests/Integration/Kd3DomainSchemaTest.php"
text = read(path)
text = replace_once(
    text,
    "Schema::hasColumn('races', 'source_category_code')",
    "Schema::hasColumn('race_results', 'source_category_code')",
    "schema category owner",
)
text = replace_once(
    text,
    "Schema::hasColumn('races', 'discipline_code')",
    "Schema::hasColumn('race_results', 'discipline_code')",
    "schema discipline owner",
)
write(path, text)

# Importer tests: HB no longer mutates canonical races; IB owns classification.
path = "tests/Integration/Kd3DomainImporterTest.php"
text = read(path)
text = replace_once(
    text,
    """        $this->assertSame('0', DB::table('races')->value('source_category_code'));
        $this->assertSame('0', DB::table('races')->value('discipline_code'));
""",
    "",
    "remove HB race classification assertions",
)
text = replace_once(
    text,
    """        $this->assertDatabaseHas('races', ['source_category_code' => '0', 'discipline_code' => '0']);""",
    """        $this->assertDatabaseHas('race_results', ['source_category_code' => '0', 'discipline_code' => '0']);""",
    "IB classification assertion",
)
text = replace_once(
    text,
    """        $raceId = DB::table('races')->insertGetId([
            'race_calendar_id' => $calendarId, 'race_no' => 1, 'source_category_code' => $category,
            'discipline_code' => $discipline, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resultId = DB::table('race_results')->insertGetId([
            'race_id' => $raceId, 'source_file_id' => $sourceId, 'source_record_number' => 1, 'result_status' => 'official',
            'declared_runner_count' => 1, 'cancelled_runner_count' => in_array($status, ['34', '35'], true) ? 1 : 0,""",
    """        $raceId = DB::table('races')->insertGetId([
            'race_calendar_id' => $calendarId, 'race_no' => 1, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resultId = DB::table('race_results')->insertGetId([
            'race_id' => $raceId, 'source_file_id' => $sourceId, 'source_record_number' => 1, 'result_status' => 'official',
            'source_category_code' => $category, 'discipline_code' => $discipline,
            'declared_runner_count' => 1, 'cancelled_runner_count' => in_array($status, ['34', '35'], true) ? 1 : 0,""",
    "result fixture classification",
)
write(path, text)

# Logical DB design.
path = "docs/database/logical-design.md"
text = read(path)
text = replace_once(
    text,
    """## Horse History

### horse_race_history
競走馬の過去競走履歴を1競走1行で管理する。

`前走`, `2走前` 等は固定カラム化せず、レース日順から導出する。

## Speed Index

### runner_speed_index
出馬表 `kol_den2.kd3` の中央平地前5走スピード指数を縦持ちする。

主な項目:
- target_race_id
- horse_id
- central_flat_run_back (1..5)
- speed_index
- reference_race_id nullable
- actual_run_back nullable
- mapping_status

地方・障害等を挟むため `central_flat_run_back` と実際の何走前かは別概念とする。
""",
    """## Horse History

`kol_uma.kd3` の直近5走・6〜55走ブロックは配布時点の再掲スナップショットであり、canonical domainには保存しない。競走履歴の事実は `race_results` / `race_result_runners` を正とする。

## Speed Index

### runner_speed_index
出馬表 `kol_den2.kd3` の中央平地前5走スピード指数をsource factとして縦持ちする。固定長上のblank slotは行にしない。

主な項目:
- race_entry_runner_id
- central_flat_run_back (1..5)
- speed_index NOT NULL
- source_file_id / source_record_number

### runner_speed_index_reference
`runner_speed_index` と参照元の `race_result_runner` を結ぶ再構築可能な派生データ。resolverが一意に安全確定できない場合は行を作らず、誤った参照を保存しない。

主な項目:
- runner_speed_index_id UNIQUE
- reference_race_result_runner_id
- resolver_version
- resolved_at

`race_results.source_category_code / discipline_code` と `race_result_runners` の完走状態・タイムから中央平地の有効実走だけを新しい順に採用する。詳細ルールは [Speed Index Reference Design](speed-index-reference.md) を参照。
""",
    "logical history/speed section",
)
text = replace_once(
    text,
    "| result | `race_results` | UNIQUE (`race_id`) |",
    "| result | `race_results` | UNIQUE (`race_id`)。KD3のsource_category / disciplineをsource lineage付きで保持 |",
    "logical result row",
)
text = replace_once(
    text,
    """| history | `horse_race_histories` | UNIQUE (`horse_id`, `history_key`)、reference raceはnullable |
| raw speed | `runner_speed_indices` | UNIQUE (`race_entry_runner_id`, `central_flat_run_back`)、CHECK 1..5 |""",
    """| raw speed | `runner_speed_indices` | UNIQUE (`race_entry_runner_id`, `central_flat_run_back`)、CHECK 1..5、speed_index NOT NULL |
| speed reference | `runner_speed_index_references` | UNIQUE (`runner_speed_index_id`)、reference result runnerへFK |""",
    "logical canonical table rows",
)
text = replace_once(
    text,
    "entry/resultのcurrent rowは `source_file_id` を持ち、新しいsource versionだけが同じ自然キーを更新する。snapshotはsource versionごとに保持し、horse historyはdeterministic `history_key` で重複を排除する。speedの統計・相対値はraw値を変更せずversion付き別テーブルへ保存する。全カラム・型・nullable・INDEXはMigrationと [ER図](kd3-domain-er.md) を参照。",
    "entry/resultのcurrent rowは `source_file_id` を持ち、新しいsource versionだけが同じ自然キーを更新する。snapshotはsource versionごとに保持する。`kol_uma` の過去走スナップショットはcanonicalへ保存せず、speed referenceはcanonical resultからresolver version付きで再構築する。speedの統計・相対値はraw値を変更せずversion付き別テーブルへ保存する。全カラム・型・nullable・INDEXはMigrationと [ER図](kd3-domain-er.md) を参照。",
    "logical canonical paragraph",
)
text = replace_once(
    text,
    "`horse_result_snapshot` は成績パック時点の `kol_uma.kd3` を保持する。",
    "`horse_result_snapshot` は成績パック時点の `kol_uma.kd3` を保持する。`race_results` はKD3成績に含まれる `source_category_code` / `discipline_code` をsource lineage付きで保持し、スピード指数参照解決に利用する。",
    "logical result classification note",
)
write(path, text)

# Architecture doc no longer describes history decoding/persistence.
path = "docs/architecture/kd3-domain-import.md"
text = read(path)
text = replace_once(
    text,
    "`Kd3LayoutRegistry` のscalar fieldとrepeated groupが調教、speed 1..5、馬履歴5+50、全odds combinationをbyte offsetからtyped valueへ変換する。",
    "`Kd3LayoutRegistry` のscalar fieldとrepeated groupが調教、speed 1..5、全odds combinationをbyte offsetからtyped valueへ変換する。`kol_uma.kd3` の直近5走・6〜55走ブロックはcanonicalで使用しないためdecodeしない。",
    "architecture boundary",
)
text = replace_once(
    text,
    "6. unresolved historyを再解決した後、speed indexのhistory参照を再構築し、touched raceのstatistics/metricsを再計算する。",
    "6. speed indexの参照をcanonical resultから再構築し、touched raceのstatistics/metricsを再計算する。",
    "architecture resolve step",
)
text = replace_once(
    text,
    """| hb | den1 / den2 / uma | entries, runners, entry snapshots, workouts, histories, speed raw/statistics/metrics |
| ib | sei1 / sei2 / optional sei3 / uma | results, runners, result snapshots, optional sanctions, histories |""",
    """| hb | den1 / den2 / uma | entries, runners, entry snapshots, workouts, speed raw/statistics/metrics |
| ib | sei1 / sei2 / optional sei3 / uma | results, runners, result snapshots, optional sanctions |""",
    "architecture artifact mapping",
)
text = replace_once(
    text,
    "historyとspeed reference、およびcommentのnullable race referenceは派生参照として再解決可能にする。history確定後にspeedの `reference_race_id / actual_run_back / mapping_status` を再構築し、commentは同一immutable sourceの再実行でも未解決raceが後からcanonical化された場合にrace referenceだけを更新する。",
    "speed referenceとcommentのnullable race referenceは派生参照として再解決可能にする。speedのsource factは `runner_speed_indices` に保持し、参照先は `runner_speed_index_references` としてcanonical resultからresolver version付きで全件再構築する。commentは同一immutable sourceの再実行でも未解決raceが後からcanonical化された場合にrace referenceだけを更新する。",
    "architecture lineage paragraph",
)
text = replace_once(
    text,
    """| horse history key/reference/result | uma / five detailed 590-byte groups and fifty 23-byte groups |
| result status/weather/track/pace/count | sei1 / race result fields |""",
    "| result category/discipline/status/weather/track/pace/count | sei1 / race result fields |",
    "architecture mapping matrix",
)
write(path, text)

# Reference design: classification is a KD3 result fact, not a canonical race column.
path = "docs/database/speed-index-reference.md"
text = read(path)
text = replace_once(
    text,
    "`races.source_category_code = '0'`",
    "`race_results.source_category_code = '0'`",
    "reference category predicate doc",
)
text = replace_once(
    text,
    "`races.discipline_code = '0'`",
    "`race_results.discipline_code = '0'`",
    "reference discipline predicate doc",
)
text = replace_once(
    text,
    """### `races`

reference判定に必要な canonical classification を保持する。

- `source_category_code`
- `discipline_code`

`kol_den1.kd3` / `kol_sei1.kd3` から同じraceへマージし、既存値と矛盾した場合は上書きせずintegrity errorにする。""",
    """### `race_results`

reference判定に必要なKD3成績のclassificationを、成績sourceのlineageと同じrowに保持する。

- `source_category_code`
- `discipline_code`

`kol_sei1.kd3` から保存し、canonical identityである `races` にはKD3固有コードを持ち込まない。""",
    "reference classification owner",
)
write(path, text)

# The runner workflow and this script are temporary and must not remain in the PR diff.
Path(".github/workflows/pr29-maintenance.yml").unlink(missing_ok=True)
Path(".github/workflows/pr29-run.yml").unlink(missing_ok=True)
Path(__file__).unlink()
