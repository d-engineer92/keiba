# Logical Database Design

## Core（Issue #3 実装済み）

設計上の `venue` / `race_calendar` / `race` / `source_identifier` は概念名。物理テーブル名はLaravel規約に合わせ `venues` / `race_calendars` / `races` / `source_identifiers` とする。各テーブルのPKは自動採番の `id`（BIGINT）で、外部コードを内部PKに使用しない。

日付は `DATE`、時刻は `TIMESTAMPTZ`。`created_at` / `updated_at` もLaravelの `timestampsTz()` によりTIMESTAMPTZ（nullable、DBでの自動更新なし）とする。取込側で日時を設定し、オフセットを含む入力は同じ時点として保存する。

### venues

競馬場の内部マスタ。外部競馬場コードは `source_identifiers` に保持する。

| カラム | 型・制約 |
| --- | --- |
| id | BIGINT PK、自動採番 |
| name | VARCHAR(255) NOT NULL、canonical name、UNIQUE |
| is_active | BOOLEAN NOT NULL、default true |
| created_at / updated_at | nullable TIMESTAMPTZ |

### race_calendars

開催日×競馬場のスケジュール。JV-Link開催スケジュール取込とKD3ダウンロード対象日の基盤。

| カラム | 型・制約 |
| --- | --- |
| id | BIGINT PK、自動採番 |
| venue_id | BIGINT NOT NULL、FK → venues.id、ON DELETE RESTRICT |
| race_date | DATE NOT NULL |
| meeting_no / meeting_day | nullable SMALLINT |
| status | VARCHAR(255) NOT NULL、default scheduled |
| first_seen_at / last_seen_at / source_updated_at | nullable TIMESTAMPTZ |
| created_at / updated_at | nullable TIMESTAMPTZ |

- UNIQUE (`race_date`, `venue_id`): 同一日・同一競馬場の重複を拒否する。
- INDEX (`race_date`): 日付によるスケジュール検索用。複合UNIQUEも日付を先頭に持つが、Issue要件どおり単独INDEXを明示する。
- 参照中の競馬場削除を拒否し、スケジュールをcascade deleteしない。

### races

個々のレース。設計上の内部 `race_id` は物理的には `races.id` を指す。

| カラム | 型・制約 |
| --- | --- |
| id | BIGINT PK、自動採番 |
| race_calendar_id | BIGINT NOT NULL、FK → race_calendars.id、ON DELETE RESTRICT |
| race_no | SMALLINT NOT NULL、CHECK (race_no > 0) |
| name | nullable VARCHAR(255) |
| scheduled_start_at | nullable TIMESTAMPTZ |
| status | VARCHAR(255) NOT NULL、default scheduled |
| created_at / updated_at | nullable TIMESTAMPTZ |

- UNIQUE (`race_calendar_id`, `race_no`): 開催内のレース番号を一意とする。
- 正数のCHECKのみ追加し、12Rを上限にはしない。仕様確認後に必要な制約を追加する。
- 参照中のスケジュール削除を拒否し、レースをcascade deleteしない。
- 両テーブルのstatusは文字列。DB enumや許可値CHECKは導入せず、厳密な状態定義は実データ仕様が確定するIssueで行う。

### source_identifiers

外部データソースの識別子を内部IDへマッピングする。内部IDから複数ソース・別名の外部識別子を引ける。

| カラム | 型・制約 |
| --- | --- |
| id | BIGINT PK、自動採番 |
| source_system | VARCHAR(255) NOT NULL（例: kd3 / jvlink） |
| entity_type | VARCHAR(255) NOT NULL（例: venue / race） |
| entity_id | BIGINT NOT NULL、対象エンティティの内部id |
| identifier_type | VARCHAR(255) NOT NULL（例: venue_code / race_key） |
| identifier_value | VARCHAR(255) NOT NULL、先頭ゼロも保持する文字列 |
| first_seen_at / last_seen_at | nullable TIMESTAMPTZ |
| created_at / updated_at | nullable TIMESTAMPTZ |

- UNIQUE (`source_system`, `entity_type`, `identifier_type`, `identifier_value`): 同じ外部識別子を異なる内部IDへ二重割当できない。
- INDEX (`entity_type`, `entity_id`): 内部エンティティから外部識別子を逆引きする。
- **物理FKの例外**: `entity_id` は `entity_type` に応じてvenues / races、将来はhorses / jockeys / trainers等を指すgeneric mappingであり、単一テーブルへのFKを定義できない。このため参照整合性はDBでは保証されない。取込処理で対象存在を確認し、削除・マッピング変更も整合性を保って実装する（取込・CRUDは本Issueの対象外）。
- venues / races にソース固有カラムを増やさない。ソース名・エンティティ種別も将来拡張可能な文字列とする。

### Migration / tests

`2026_09_05_000000` から `000003` の順に venues → race_calendars → races → source_identifiers を作成し、rollbackは逆順に行う。テーブル作成・制約はMigrationのみで管理する。初期Seeder・Eloquent Model・CRUD/APIは今回追加しない。

`tests/Integration/CoreSchemaTest.php` で実PostgreSQLの型、PK・INDEX、nullable/default、FKの挿入・削除制約、UNIQUE、race_noのCHECK、識別子のスコープ、タイムゾーン付き時刻を検証する。空DBからの全Migrationは既存CIの `migrate:fresh --env=testing` で確認する。既存のtesting DB safety guardを維持し、テスト対象は `keiba_test` のみ。

### horse / jockey / trainer（今後実装）

内部エンティティ。KD3/JV-Link固有コードを主キーにしない。例: KD3 horse_code / JV-Link血統登録番号 / 騎手コード / 調教師コードはsource_identifiersでマッピングする。

## KD3 Entry

- race_entry
- race_entry_runner
- horse_entry_snapshot
- runner_workout
- runner_speed_index

`horse_entry_snapshot` は出馬表パック時点の `kol_uma.kd3` を保持する。

## KD3 Result

- race_result
- race_result_runner
- horse_result_snapshot
- race_sanction
- race_comment

`horse_result_snapshot` は成績パック時点の `kol_uma.kd3` を保持する。`race_results` はKD3成績に含まれる `source_category_code` / `discipline_code` をsource lineage付きで保持し、スピード指数参照解決に利用する。

## Horse History

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

`race_results.source_category_code / discipline_code` と `race_result_runners` の完走状態・タイムから中央平地の有効実走だけを新しい順に採用する。候補日からtarget前日までの中央開催日に現行versionのIB成功取込が1日でも欠ける場合はreferenceを作らない。詳細ルールは [Speed Index Reference Design](speed-index-reference.md) を参照。

### race_speed_statistics
レース単位の統計値。

- valid_count
- excluded_count
- mean
- median
- stddev
- min/max
- MAD
- calculation_version

### race_speed_metric
馬ごとのレース内相対指標。

- speed_rank
- percentile
- zscore
- deviation_score
- robust_zscore
- robust_deviation_score
- is_outlier
- calculation_version

生のスピード指数は必ず保持し、派生値だけに置き換えない。

## Odds

### race_odds
KD3予想/確定オッズを券種・組合せ単位で縦持ちする。

### odds_snapshot
JV-Linkの時系列単勝・複勝を保存する。

物理テーブルは `jvlink_events`（idempotency/audit）、`race_odds_snapshots`、`race_odds_snapshot_items`。snapshotはsource eventと1対1、itemは `(snapshot_id, bet_type, horse_no)` unique。race時系列は `(race_id, snapshot_at)`、runner eventは `(race_id, horse_no)` を索引化する。

主な項目:
- race_id
- horse_id / horse_no
- odds_type
- observed_at / published_at
- captured_at
- odds
- total_votes (取得可能な範囲)
- source_type
- collection_mode

## JV-Link Live

- realtime_event
- runner_status_event
- jockey_change_event
- body_weight_snapshot
- weather_track_history

速報は現在値だけに上書きせず、観測した履歴を残す。

物理テーブルは `runner_status_events`、`jockey_change_events`、`body_weight_snapshots`、`weather_track_events`。全rowが `jvlink_event_id` unique FKを持ち、古いeventの到着でも既存履歴を更新しない。backfill auditは `jvlink_backfill_runs`、日/race coverageは `jvlink_backfill_coverages` に保持する。

## Ingest / Audit

### source_files（Issue #5 実装済み）

KD3から取得したLZH原本のimmutable version metadata。`source_system + artifact_type + race_date + sha256` をUNIQUEとし、同名再発行でも内容が変われば別version、同一内容の再取得では既存versionを再利用する。

| カラム | 型・制約 |
| --- | --- |
| id | BIGINT PK、自動採番 |
| source_system / artifact_type | VARCHAR(255) NOT NULL |
| race_date | DATE NOT NULL |
| original_filename / storage_disk | VARCHAR(255) NOT NULL |
| storage_path / source_url | TEXT NOT NULL |
| sha256 | CHAR(64) NOT NULL、小文字hex CHECK |
| size_bytes | BIGINT NOT NULL、0以上 |
| downloaded_at | TIMESTAMPTZ NOT NULL |
| created_at / updated_at | nullable TIMESTAMPTZ |

### kd3_artifact_statuses（Issue #5 実装済み）

`race_date + artifact_type` をUNIQUEとする現在の観測状態。`status` は `pending / downloaded / not_available / failed` を使うが、拡張可能な文字列としてDB enumにはしない。`latest_source_file_id` は最新の保存成功versionへのnullable FK（ON DELETE RESTRICT）。`last_checked_at`, `last_success_at`, `attempt_count`, `last_http_status`, `last_error_category` を持つ。

未公開・失敗でも再確認を続ける。`not_available` / `failed` は既存の `latest_source_file_id` と `last_success_at` を消さない。LZH保存後のDB transaction失敗では、その試行で新規作成したファイルをcleanupする。

- raw_record (必要なデータのみ)
- format_version
- import_job
- mapping_audit
- reconciliation_log
parser version、format version、取込状態はIssue #6以降で追加する。

### kd3_parse_runs（Issue #6）

KD3原本の再検証履歴。source file、parser/spec version、status、件数、開始/終了時刻、失敗カテゴリと安全な位置情報を保存し、レコード本文は保存しない。

## KD3 canonical domain（Issue #7 実装済み）

Migration `2026_09_05_000006_create_kd3_domain_tables.php` をKD3 domainの初期schemaとし、後続migration（現在は `2026_09_06_000009_refactor_kd3_speed_references.php`）で互換性を保ちながら進化させる。すべてのFKは削除をRESTRICTし、source lineageを保持する。

| 領域 | テーブル | 主な自然キー・制約 |
| --- | --- | --- |
| identity | `horses`, `jockeys`, `trainers` | BIGINT内部PK。外部codeは `source_identifiers` |
| entry | `race_entries` | UNIQUE (`race_id`) |
| entry runner | `race_entry_runners` | UNIQUE (`race_entry_id`, `horse_id`)、UNIQUE (`race_entry_id`, `horse_no`) |
| snapshot | `horse_entry_snapshots`, `horse_result_snapshots` | UNIQUE (`source_file_id`, `horse_id`) |
| workout | `runner_workouts` | UNIQUE (`race_entry_runner_id`, `sequence_no`) |
| result | `race_results` | UNIQUE (`race_id`)。KD3のsource_category / disciplineをsource lineage付きで保持 |
| result runner | `race_result_runners` | UNIQUE (`race_result_id`, `horse_id`)、horse_noも一意 |
| optional result | `race_sanctions` | UNIQUE (`source_file_id`, `source_record_number`) |
| comments | `race_comments` | UNIQUE (`source_file_id`, `source_record_number`, `comment_type`) |
| raw speed | `runner_speed_indices` | UNIQUE (`race_entry_runner_id`, `central_flat_run_back`)、CHECK 1..5、speed_index NOT NULL |
| speed reference | `runner_speed_index_references` | UNIQUE (`runner_speed_index_id`)、reference result runnerへFK |
| speed aggregate | `race_speed_statistics` | UNIQUE (`race_id`, `central_flat_run_back`, `calculation_version`) |
| speed metric | `race_speed_metrics` | UNIQUE (`runner_speed_index_id`, `calculation_version`) |
| odds | `race_odds` | UNIQUE (`race_id`, `odds_phase`, `bet_type`, `combination_key`) |
| audit | `kd3_import_runs` | source / parser / importer / spec version、件数、safe error context |

entry/resultのcurrent rowは `source_file_id` を持ち、新しいsource versionだけが同じ自然キーを更新する。snapshotはsource versionごとに保持する。`kol_uma` の過去走スナップショットはcanonicalへ保存せず、speed referenceはcanonical resultからresolver version付きで再構築する。speedの統計・相対値はraw値を変更せずversion付き別テーブルへ保存する。全カラム・型・nullable・INDEXはMigrationと [ER図](kd3-domain-er.md) を参照。

## Environment separation

同一Migrationを以下へ適用する。

- keiba_dev
- keiba_test
- keiba_perf
- keiba_prod

テストデータ・性能試験データを本番データと混在させない。
