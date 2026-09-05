# Data Flow

## 1. 開催スケジュール

```text
JV-Link
  ↓
C# Collector
  ↓
Laravel Ingest API
  ↓
race_calendar
```

Issue #4の実装・契約は [開催スケジュール取り込み](jvlink-schedules.md) を参照。物理テーブルは `race_calendars`。この手動snapshot PoCではOutboxを使わず、速報向け永続OutboxはIssue #8で対応する。

`race_calendar` は開催日×競馬場の基礎データとして永続化し、KD3 Downloader と当日 Collector の起動判定に利用する。

## 2. KD3 ダウンロード

```text
race_calendar
  ↓
開催日のみ抽出
  ↓
KD3 Download Planner
  ↓
競馬道OnLineへログイン
  ↓
日付bundle ZIP取得（同一session）
  ↓
artifact別LZH選択・原本保存 + SHA-256
  ↓
展開
  ↓
KD3 Parser
  ↓
Validation
  ↓
PostgreSQL
```

### 日次系

- hb: 出馬表
- ib: 成績
- jb: 予想オッズ
- kd: 確定オッズ
- lb: 成績用コメント
- mb: 出馬用コメント

同一開催日でも提供タイミングが異なるため、`race_date` 単位の downloaded=true ではなく、artifact type ごとに取得状態を管理する。

### Planner / command

`race_calendars` から対象範囲内の `DISTINCT race_date` を抽出する。`cancelled` / `deleted` 以外が1行以上ある日だけが対象で、外部入力だけでは未登録日を取得しない。日付は Asia/Tokyo とする。

```bash
php artisan kd3:download --date=2026-09-05
php artisan kd3:download --from=2026-08-01 --to=2026-09-05 --type=hb --type=jb
```

引数なしは Asia/Tokyo の当日を `race_calendars` と交差させる。取得済みも毎回 remote を再確認し、同 SHA はversionを増やさず、異なる SHA は新versionとして保持する。定期実行の頻度は固定しない。

### 確認済み HTTP contract（2026-09-05）

- base URL: `https://www.keibado.net`。資格情報を送る host は設定した HTTPS host と完全一致する場合だけ許可する。
- login: `POST /kdata/login.php`。form は `fromform=2`, `user_id`, `user_pass`, `btn_submit=ログイン`。成功時は同じ cookie session で `/kdata/option.php` に遷移する。
- daily bundle: `GET /kdata/select_download_core.php?mmdd=MMDD&yyyy=YYYY&kdx=kd3`。
- 成功 response は HTTP 200、`Content-Type: application/zip; name="kd3_YYYYMMDD.zip"`、Content-Dispositionあり、ZIP signature `PK`。bundle内の日次原本は `kd3_{artifact_type}YYMMDD.lzh`。
- 同日bundleは1 command内で1回だけ取得し、ZIP entry の有無を artifact ごとに判定する。entry欠如は `not_available`。認証画面、非ZIP、壊れたZIP、同一typeの曖昧な複数entryは成功にしない。
- 未掲載日は HTTP 200 / text/html / `データなし`（Content-Dispositionなし）であり `not_available` とする。401/403・ログイン画面は `authentication`、404/410も `not_available`、5xxは `server`、接続失敗は `network`、その他のmetadata/signature不正は `invalid_response` / `invalid_archive` に分類する。response本文や資格情報はDB・ログへ保存しない。

### Storage / retention

Laravel Filesystem の `KD3_STORAGE_DISK`（既定 `local` = `storage/app/private`）を使う。logical path は `kd3/raw/YYYY/MM/YYYY-MM-DD/{artifact_type}/{sha256}.lzh`。LZHはIssue #6以降のcanonical raw inputとして不変・非公開で保持し、同一SHAを上書きしない。download ZIPは検証・抽出後に破棄し、展開済みKD3 textは本Issueで永続化しない。object storageへはdisk設定の差し替えで移行できる。

### Parser（Issue #6）

`php artisan kd3:parse --source-file=<id>` は `source_files` のサイズ/SHA-256を検証してからLZHを一時展開する。Parser coreはbyte slice後にCP932をdecodeする固定長readerとartifact別validatorから構成し、source/artifact/internal filename contextを持つtyped DTOを返す。domain tableへの保存はIssue #7で行う。実行履歴は `kd3_parse_runs` に保存する。詳細は [KD3 Parser設計](kd3-parser.md) を参照。

### マスタ系

- 騎手
- 厩舎
- 種牡馬
- 3代血統
- 5代血統

日次系とは別ジョブとして扱う。

## 3. KD3 パース

基本方針は最新仕様を canonical layout とする。

確認済みサンプル:

- 2007-10-28
- 2007-11-25
- 2019-12-28
- 2020-12-27
- 2025-06-28

上記サンプルでは主要日次ファイルの固定長、CR/LF、主要キー位置が最新仕様と一致している。

古い時代に存在しない項目は NULL とし、物理 Validation に失敗した場合のみ旧 layout を追加する。

## 4. JV-Link 過去時系列

```text
JV-Link historical timeseries
  ↓
C# Collector / Backfill
  ↓
Laravel Ingest API
  ↓
odds_snapshot
```

初期対象:

- 単勝
- 複勝

過去レースの確定値は KD3 を主データとし、JV-Link から重複取得しない。

## 5. JV-Link 当日

```text
JV-Link
  ↓
C# Collector
  ↓
SQLite Outbox
  ↓
Laravel Ingest API
  ↓
PostgreSQL
```

対象:

- 単勝オッズ
- 複勝オッズ
- 出走取消
- 競走除外
- 騎手変更
- 馬体重
- 馬体重増減
- 天候
- 馬場状態

速報・時系列は一度観測した情報を自前DBで履歴として保持する。

## 6. Collector Outbox

JV-Link 速報は後から取得できない可能性があるため、C# Collector は送信前に SQLite Outbox へ保存する。

```text
JV-Link取得
  ↓
SQLiteへPENDING保存
  ↓
API送信
  ├─ 成功 → SENT
  └─ 失敗 → 再送
```

Laravel API 側は `source_event_id` またはレコードハッシュで冪等にする。
