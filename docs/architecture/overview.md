# Architecture Overview

## 目的

KD3 と JV-Link を組み合わせ、過去データの蓄積・分析と、当日の時系列・速報データの保存を同一基盤で扱う。

## 全体構成

```text
Windows
├─ JV-Link
└─ C# Collector
   ├─ JV-Link access
   ├─ SQLite Outbox
   └─ HTTPS/HTTP
        ↓
WSL2 Ubuntu 26.04 / 将来のCloud
└─ Docker / Application
   ├─ Laravel
   │  ├─ KD3 Downloader
   │  ├─ KD3 Importer
   │  ├─ JV Ingest API
   │  └─ Analysis / Web / API
   └─ PostgreSQL
```

ローカルでは Laravel/PostgreSQL を WSL2 上の Docker Compose で動かす。JV-Link は Windows 依存のため Windows 側に残す。

将来クラウドへ移行する場合も、C# Collector の送信先 API を変更することで Laravel/PostgreSQL 側を移設できる構成を目標とする。

## 技術方針

- Backend: PHP / Laravel
- Database: PostgreSQL
- DB schema: Laravel Migration を唯一の正とする
- Local: WSL2 Ubuntu 26.04 + Docker Compose
- JV-Link: C# Collector
- Collector → Application: Laravel Ingest API
- Collector の一時保存: SQLite Outbox
- CI/CD: GitHub Actions
- Cache / Queue: 必要になった段階で Redis を導入可能な構造にする
- 将来の分析/ML: Python を追加可能にする

## Timezone policy

競馬の開催日・日次処理・「今日/昨日/未来」などの業務日付は **Asia/Tokyo** を基準にする。Laravel の application timezone と PostgreSQL の application connection timezone も Asia/Tokyo を既定とし、`CURRENT_DATE` や日付切り出しが UTC の日付境界に引きずられないようにする。

一方、JV-Link の published/effective/captured/received 時刻など「絶対時刻」として扱う値は `TIMESTAMPTZ` で保持してよい。保存形式を UTC に正規化しても、`race_date` や日次集計などの業務値を導出する前に必ず Asia/Tokyo に変換する。

## データソースの役割

### KD3

- 出馬表
- 成績
- 競走馬
- 騎手
- 厩舎
- 種牡馬
- 血統
- 予想オッズ
- 確定オッズ
- コメント
- 競馬ブック独自のスピード指数・調教等

KD3 は履歴・確定値・競馬ブック独自情報の主データとして扱う。

### JV-Link

- 開催スケジュール
- 過去取得可能な単勝・複勝時系列
- 当日の単勝・複勝時系列
- 出走取消
- 競走除外
- 騎手変更
- 馬体重
- 馬体重増減
- 天候
- 馬場状態

JV-Link は時間軸が重要なデータを中心に扱う。

## 内部ID

KD3 と JV-Link のコード体系を直接主キーにしない。

- `race_id`
- `horse_id`
- `jockey_id`
- `trainer_id`
- `venue_id`

を内部IDとして持ち、外部ソースの識別子はマッピングテーブルで管理する。

## 現在のスコープ外

- KD3 特別登録データ (`kol_tok1.kd3`, `kol_tok2.kd3`) は初期実装では対象外
- Kubernetes / Kafka 等の大規模基盤は導入しない
- Production cloud は将来対応
