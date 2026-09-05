# JV-Link 単複時系列・速報・Outbox

## 境界

`IJvOddsHistoryClient` と `IJvRealtimeClient` は Issue #4 の `IJvLinkClient` から分離する。実 COM は Windows x86 / STA 上で逐次実行し、取得した normalized event は HTTP より前に `KEIBA_OUTBOX_PATH` の SQLite へ commit する。送達は at-least-once、Laravel は `source_event_id` で冪等にする。

```text
JV-Link (JVRTOpen/JVRead/JVClose)
  -> typed parser -> source envelope
  -> SQLite outbox (pending commit)
  -> POST /api/internal/v1/jvlink/events
  -> jvlink_events + append-only typed history
```

## 公式 contract

根拠は [公式 SDK 提供ページ](https://jra-van.jp/dlb/sdv/sdk.html)、JV-Link インターフェース仕様書 4.9.0.1、[JV-Data 仕様書 4.9.0.1](https://jra-van.jp/dlb/sdv/sdk/JV-Data4901.pdf)。2026-08 の SDK 5.0.0 でも interface / data document の版は変更されていない。実機は Issue #4 と同じ 32bit ProgID `JVDTLab.JVLink` を使用する。

| 用途 | data spec | record | key / 単位 | 保証期間 |
| --- | --- | --- | --- | --- |
| 時系列単複 | `0B41` | `O1` | `YYYYMMDDJJRR`、race | 1年 |
| 速報単複 | `0B31` | `O1` | `YYYYMMDDJJRR`、race | 1週間 |
| 馬体重・天候馬場 | `0B11` | `WH` / `WE` | race または開催日 | 1週間 |
| 開催変更一括 | `0B14` | `WE` / `AV` / `JC` 等 | `YYYYMMDD` | 1週間 |
| 開催変更指定 | `0B16` | requestに対応するrecord | JVWatchEvent由来request | 1週間 |

polling command は `0B14` を使う。`0B16` は JVWatchEvent から得た request parameter がある場合の境界であり、推測したkeyで呼ばない。各 open は `JVInit("UNKNOWN") -> JVRTOpen(dataSpec,key) -> JVRead loop -> JVClose`。Read は `-1` をファイル境界、`0` を終了とし、それ以外の負数は失敗する。複数 COM 呼出を並列化しない。

### record layout（1始まり）

- `O1`: 962 bytes。race date 12/8、venue 20/2、meeting 22/4、race no 26/2、発表月日時分 28/8。単勝は 44 から 28×8（horse no 2 + odds 4 + popularity 2）、複勝は 268 から 28×12（horse no 2 + min 4 + max 4 + popularity 2）。本 Issue は枠連部を保存しない。
- `WH`: 847 bytes。race key は 12..27、発表月日時分 28/8、36 から 18×45。各要素の horse no は +1/2、body weight は +39/3、増減符号は +42/1、差は +43/3。馬名は identity 解決に使わない。
- `WE`: 42 bytes。race date 12/8、venue 20/2、meeting 22/4、発表月日時分 26/8、変更識別 34/1、現在の weather/turf/dirt は 35..37。
- `AV`: 78 bytes。data category `1=cancelled`, `2=excluded`、race key 12..27、発表月日時分 28/8、horse no 36/2、reason 74/3。
- `JC`: 161 bytes。race key 12..27、発表月日時分 28/8、horse no 36/2、新騎手 code 77/5、旧騎手 code 120/5。名前だけでは mapping しない。

長さと CRLF を検証する。オッズの space / `0000` / `----` / `****` / 上限値は nullable value と status に正規化し、値を推測しない。発表時刻が all-zero/space の record は `source_published_at` と `snapshot_at` を null にし、`captured_at` で代用しない。

## ID と時刻

`source_event_id` は `data spec + record type + official race key + data category + 発表月日時分 + horse no/change discriminator` という source metadata を SHA-256 にした deterministic ID。payload value だけの hash ではないため、同じ値へ戻った別 cycle も別 event になる。`payload_sha256` は normalized payload の integrity / conflict 判定専用。

- `source_published_at`: recordの発表月日時分が有効な場合だけ（JSTからoffset付き時刻へ変換）
- `effective_at`: JV-Dataが別の有効時刻を明示するeventのみ。本実装対象では null
- `captured_at`: Collector観測時刻、必須
- `received_at`: Laravel transaction内の受信時刻

## canonical resolve

venue は `source_identifiers(jvlink, venue, venue_code)`、calendar は `race_date + venue_id`、race は `race_calendar_id + race_no` で既存 row のみを解決する。JV-Link full race identifier は解決済み `races.id` に mapping する。異なる mapping は 409。horse は既存 entry/result の `race_id + horse_no` が一意なら解決し、未解決なら null（新規 horse を作らない）。jockey は stable code がある場合のみ作成/mappingする。

## retention / coverage

正式要求範囲は 2008-01-01 以降。ただし公式保証は `0B41` の直近1年である。古い no-data を retention 外と推測せず、実機の明確な戻り値または運用確認がある場合だけ `outside_provider_retention`、それ以外は `no_data` とする。`jvlink_backfill_runs` と `jvlink_backfill_coverages` は `/api/internal/v1/jvlink/backfills` で冪等更新できる。実測最古日は [Issue #8 検証記録](../testing/issue-8-verification.md) に記録する。

## Outbox

SQLite は WAL + `synchronous=FULL`、schema version 2。`source_event_id` unique、pending scan indexと日単位 `backfill_coverages` を持つ。success後もrowを消さずsentにする。HTTP success後sent更新前のcrashは同じIDを再送し、Laravelがunchangedを返す。network/timeout/429/5xxは上限1時間の指数backoff+jitter、その他の4xxはdead。response body、normalized payload、tokenをerror messageへ含めない。
