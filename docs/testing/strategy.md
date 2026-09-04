# Testing Strategy

## 1. Unit Test

DBを使わない純粋ロジックを対象とする。

例:
- 固定長フィールドの変換
- スピード指数の数値変換
- 偏差値計算
- MAD / robust z-score
- URL生成
- ID変換ロジック

## 2. Integration Test

PostgreSQL `keiba_test` を使用する。

例:
- KD3レコード → Parser → DB
- race / horse source identifier mapping
- Laravel Ingest API → DB
- 冪等性
- Migration

## 3. Data Quality Test

エラーにならず誤ったデータが入ることを防ぐため、データ品質テストを独立して持つ。

KD3例:
- file size % record length == 0
- record末尾CR/LF
- 日付・コード値妥当性
- den1頭数 = den2 grouped count
- sei1頭数 = sei2 grouped count
- den2 horse code と出馬表パックumaの対応
- sei2 horse code と成績パックumaの対応
- 一部券種・コメントは全レース/全馬存在を要求しない

JV-Link例:
- 同じイベント再送で二重登録されない
- source identifier mapping が一意
- Collector再起動後もOutbox未送信データを再送できる

## 4. Feature / API Test

- JV-Link Ingest API
- Health endpoint
- 認証/認可（導入時）
- 不正payload
- duplicate payload

## 5. Performance Test

`keiba_perf` を使用し、通常テストとは分離する。

Factory/Seederまたは実KD3データを用いて大量データを生成する。

主な計測対象:
- 馬の過去競走履歴
- レース出走馬一覧
- スピード指数/統計
- 時系列オッズグラフ
- 当日最新状態

PostgreSQLでは `EXPLAIN (ANALYZE, BUFFERS)` を使用して実行計画を確認する。

性能試験はpushごとのCIでは実行しない。手動または定期実行とする。

## 6. Deploy Smoke Test

将来のデプロイ後に最低限以下を確認する。

- Application起動
- DB接続
- Migration状態
- Health endpoint
- 主要API応答

## DB isolation

- dev: keiba_dev
- automated test: keiba_test
- performance: keiba_perf
- production: keiba_prod

本番DBをテストから参照できない構成にする。
