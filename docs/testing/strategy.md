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

## 7. GitHub Actions CI

`.github/workflows/ci.yml` は `pull_request` と `main` への `push` で実行する。

- PHP quality and tests: PHP 8.5、PostgreSQL 18 service、lockに従ったComposer install、strict validate、Pint、Larastan level 5、空DBからのMigration、Unit / Integration / Featureの各suite。
- Docker build and Compose tests: Docker build、Composeの起動、test DBへのMigration、全suite、HTTP health確認。通常開発環境の起動経路もCIで保証する。
- CI serviceは `127.0.0.1 / ci_test`、Composeは `postgres-test / keiba_test` を使い、両構成で同じガードとテストを実行する。

### Testing DB safety

`AppServiceProvider` がアプリ起動時に `TestingDatabaseGuard` を呼び、DBアクセス前に検査する。`pgsql` 接続・driverとDB名 `keiba_test` を必須とし、config cache、接続URL、read/write接続上書きを拒否する。hostname / port / usernameは実行環境で指定する。`.env.testing` はローカルの既定値、CIはジョブの環境変数を使う。PHPUnitでDB値を強制上書きしないため、危険な設定は隠されず拒否される。

UnitテストはDBを使わずガードの許可・拒否を検証する。Integrationテストは実DBの名前・ユーザーとMigration結果を検証する。Featureテストはprovider経由の検査、子プロセスでのMigration / PHPUnitへの危険な環境変数、local環境のconfig cacheによる検査回避を検証する。`--env=testing` もcacheの有無にかかわらず検出する。

このガードはアプリの既定接続の誤設定を防ぐ。CI/ローカルには本番の資格情報を置かず、将来明示的な別接続を追加する場合も環境分離を維持する。

## 8. Collector / 開催スケジュール

.NET 10のxUnitをLinux / Windows CIで実行し、合成YSの固定長・日付・コード・nullable変換、Fake IJvLinkClientの再実行、HTTP JSON/Bearer契約、APIエラー分類を検証する。Windows CLIはvendor DLLなしでbuildする。Laravel Featureではtest PostgreSQLを使い認証・validation・mapping競合・transaction rollback・自然キーupsert・first/last seen・古いsnapshotの拒否を確認する。

実JV-Link E2EはWindowsローカルからdev DBだけで実施し、通常CIには含めない。原本・実payload・利用キー・tokenをfixtureやログへcommitしない。Issue #4の手順と結果は [検証記録](issue-4-verification.md) を参照。

## 9. KD3 Downloader

通常テストは自作の最小ZIP/LZH bytesとHTTP fakeだけを使用する。Plannerの日付交差・重複排除・中止除外、login requestとsession再利用、bundle cache、ZIP metadata/signature、entry選択、SHA versioning、current status、再取得、失敗時latest保持を Unit / Integration / Feature に分けて確認する。実アカウント・Cookie・有償データはCIへ渡さない。

実機E2Eは `keiba_dev` だけで実施し、原本はprivate disk、response ZIPは一時領域に置く。結果と未検証範囲は [Issue #5検証記録](issue-5-verification.md) を参照。

## 10. KD3 Parser

CIでは合成recordでrecord length余り、CRLF、byte slice後CP932変換、nullable、先頭ゼロcode、strict date/numeric、field診断、expected file set、hb/ibのcount・参照・duplicate、sei3とodds/commentのoptional性、integrity、成功/失敗auditを検証する。自作の最小LZH fixtureでruntimeの `lha` によるlist・事前path検査・展開も実行する。実LZHはGit/CIへ入れずprivate disk上でのみ回帰確認する。
