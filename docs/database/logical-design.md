# Logical Database Design

## Core

### venue
競馬場の内部マスタ。

### race_calendar
開催日×競馬場の開催スケジュール。JV-Linkから取得して永続化する。

### race
個々のレース。内部 `race_id` を主キーとする。

### horse / jockey / trainer
内部エンティティ。KD3/JV-Link固有コードを主キーにしない。

### source_identifier
外部データソースの識別子を内部IDへマッピングする。

例:
- KD3 horse_code
- JV-Link 血統登録番号
- KD3/JV-Link race key
- 騎手コード
- 調教師コード

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

`horse_result_snapshot` は成績パック時点の `kol_uma.kd3` を保持する。

## Horse History

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

## Ingest / Audit

- source_file
- raw_record (必要なデータのみ)
- format_version
- import_job
- mapping_audit
- reconciliation_log
- kd3_artifact_status

KD3原本LZHのSHA-256、parser version、format version、取得・取込状態を記録する。

## Environment separation

同一Migrationを以下へ適用する。

- keiba_dev
- keiba_test
- keiba_perf
- keiba_prod

テストデータ・性能試験データを本番データと混在させない。
