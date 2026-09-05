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
日付からダウンロードURL生成
  ↓
ZIP取得
  ↓
LZH原本保存 + SHA-256
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
