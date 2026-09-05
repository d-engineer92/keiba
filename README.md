# keiba

競馬道OnLine KD3 と JRA-VAN JV-Link を組み合わせ、競走データの蓄積・分析・可視化を行う個人開発プロジェクトです。

## 設計方針

- Backend: PHP 8.5 / Laravel 13
- Database: PostgreSQL 18
- Local runtime: WSL2 Ubuntu 26.04 + Docker Compose、Nginx + PHP-FPM
- JV-Link collector: C# / .NET 10 / Windows x86（開催スケジュールPoC）
- Schema management: **Laravel Migration を唯一の正**とする
- KD3: 確定・履歴・競馬ブック独自情報の主データ
- JV-Link: 開催スケジュール、過去時系列、当日速報・時系列データの取得

参照: [技術選定](docs/adr/ADR-001-technology-stack.md)、[構成](docs/architecture/overview.md)、[データフロー](docs/architecture/data-flow.md)、[DB論理設計](docs/database/logical-design.md)、[テスト戦略](docs/testing/strategy.md)。開発基盤に加え、競馬場・開催スケジュール・レース・外部識別子のCoreテーブルをMigrationで管理します。

## 前提

WSL2 Ubuntu 上で Docker Engine と Docker Compose v2 が使用できること（Docker Desktop の WSL integration または WSL 内の Docker Engine）。`docker version` と `docker compose version` が成功することを確認してください。ホスト側の PHP / Composer / Node.js は不要です。

KD3 Downloaderを実行する場合は、競馬道データ会員の認証情報をGit管理外の `.env` だけに設定します。

```dotenv
KD3_USERNAME=
KD3_PASSWORD=
KD3_BASE_URL=https://www.keibado.net
KD3_STORAGE_DISK=local
```

`KD3_BASE_URL` はHTTPSのみを許可し、設定host以外へ資格情報を送信しません。実値・Cookie・取得したZIP/LZHをGitへ追加しないでください。

## 初回起動

```bash
cp .env.example .env
# id -u / id -g が 1000 以外なら .env の LOCAL_UID / LOCAL_GID を変更
# ポート競合時は APP_PORT と APP_URL を変更

docker compose up -d
# 初回はイメージのビルドと composer.lock に従った依存取得を実行
# APP_KEY が空なら自動生成（.env に保存）
docker compose logs -f app
# ログ確認を終えるには Ctrl+C（コンテナは停止しない）
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
curl --fail http://localhost:8080/health
```

`http://localhost:8080/` はアプリ名を、`/up` は Laravel の起動状態を返します。`/health` は DB 接続成功時に HTTP 200 と `{"status":"ok"}`、失敗時に HTTP 503 を返します。Migration 状態は別途 `migrate:status` で確認します。Web は localhost のみに公開し、DBポートはホストに公開しません。

初回起動には Docker イメージと Composer パッケージを取得するネットワーク接続が必要です。`docker compose ps` で状態、`docker compose logs app web postgres postgres-test` で障害を確認できます。PHP拡張など Dockerfile 変更時は `docker compose up -d --build` を実行します。

## DB・永続化

| 用途 | Compose サービス | DB / ユーザー | 保存先 |
| --- | --- | --- | --- |
| 開発 | postgres | keiba_dev | postgres-dev volume |
| 自動テスト | postgres-test | keiba_test | postgres-test volume |
| 性能試験（任意） | postgres-perf | keiba_perf | postgres-perf volume |

DBは用途ごとに PostgreSQL インスタンス、資格情報、ボリュームを分離しています。testインスタンスに dev/prod DB は作成しません。本番接続情報はこの構成に含めません。パスワードはローカル開発専用です。dev の `DB_PASSWORD` を変更する場合、既存ボリューム内のユーザーパスワードは自動変更されません。

PostgreSQL 18 のデータは `/var/lib/postgresql` にマウントした named volume、Laravel の `storage/`（ログ・アップロード等）と `.env`、`vendor/` はホストのプロジェクトディレクトリに保存します。アプリコンテナを再作成しても保持されます。ホストのユーザーIDで PHP-FPM / Composer を動かすため、生成ファイルも同ユーザーの所有になります。

SQLの初期化スクリプトではアプリのテーブルを作成しません。全環境に `database/migrations/` の同一 Migration を適用します。Laravel標準の users / cache / jobs 等に加え、venues / race_calendars / races / source_identifiers をMigrationで作成します。詳細はDB論理設計を参照してください。

## テスト

```bash
docker compose exec app php artisan migrate:fresh --env=testing
docker compose exec app php artisan test
# または docker compose exec app composer test
```

`.env.testing` はローカル用の `postgres-test:5432 / keiba_test` を指定します。CIでは外部の `DB_HOST`・`DB_PORT`・`DB_USERNAME`・`DB_PASSWORD` で接続先を設定できます。`phpunit.xml` は testing 環境を強制しますがDB設定を上書きしません。誤った `DB_DATABASE` や `DB_URL` が渡された場合は、黙って修正せず起動時に拒否します。アプリ起動時にテストDB設定を検査し、`pgsql` 以外・`keiba_test` 以外・接続URL・read/write接続上書き・config cache の使用を検出したらDB操作前に失敗します。ローカル開発では `config:cache` を使わず、作成済みなら `docker compose exec app php artisan config:clear` を実行してください。

Integration/Featureテストは実 PostgreSQL に Migration を適用し、接続先DB・ユーザー、テーブル、health応答を確認します。UnitテストはDBを使用しません。

## KD3 Downloader

`race_calendars` に存在する開催日だけを対象に、日次 KD3 bundleからartifact原本を取得します。

```bash
docker compose exec app php artisan kd3:download --date=2026-09-05
docker compose exec app php artisan kd3:download --from=2026-08-01 --to=2026-09-05 --type=hb --type=jb
```

引数なしはAsia/Tokyoの当日です。同一対象も毎回再取得してSHAを比較します。保存先は既定で `storage/app/private/kd3/raw/YYYY/MM/YYYY-MM-DD/{type}/{sha256}.lzh`、取得履歴は `source_files`、現在状態は `kd3_artifact_statuses` です。login/download契約とretentionは [データフロー](docs/architecture/data-flow.md)、検証結果は [Issue #5検証記録](docs/testing/issue-5-verification.md) を参照してください。

## Migration・初期化

```bash
# 追加・適用・直前のバッチを戻す
docker compose exec app php artisan make:migration create_example_table
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:rollback

# 開発DBの全テーブルを削除して作り直す（開発データは失われる）
docker compose exec app php artisan migrate:fresh

# テストDBのみ初期化
docker compose exec app php artisan migrate:fresh --env=testing
```

## 性能試験DB（任意）

```bash
cp .env.perf.example .env.perf
docker compose --profile perf up -d
docker compose exec app php artisan migrate --env=perf
# 性能試験データの投入・計測コマンドは今後追加
```

通常の `php artisan test` は perf を使用しません。`keiba_prod` は将来のデプロイ先で独立して用意し、同じ Migration を適用します。

## 停止・再開・完全初期化

```bash
# 停止・再開（データ保持）
docker compose stop
docker compose up -d

# コンテナ・ネットワークを削除（DBデータ保持）
docker compose --profile perf down

# 全用途のDBボリュームも削除（dev/test/perf の全データが失われる）
docker compose --profile perf down --volumes
docker compose up -d
docker compose exec app php artisan migrate
```

`down --volumes` はホスト側の `storage/`、`.env`、`vendor/` を削除しません。

今回の実行結果と未検証範囲は [開発基盤の検証記録](docs/testing/local-environment-verification.md) を参照してください。

## CI / 静的解析

Pull Request と `main` への push で [CI](.github/workflows/ci.yml) が実行されます。

- PHP 8.5 / PostgreSQL 18 serviceでComposer検証・依存取得・Pint・Larastan・空のtest DBへのMigration・Unit / Integration / Featureテストを実行。
- 別ジョブでDocker imageをビルドし、Compose環境の起動・同じテスト・HTTP応答も検証。
- 性能試験・本番デプロイ・実データ・秘密情報は含めません。

ローカルでの品質チェック:

```bash
docker compose exec app composer install
docker compose exec app composer validate --strict
docker compose exec app composer lint
docker compose exec app composer analyse
docker compose exec app php artisan migrate:fresh --env=testing
docker compose exec app php artisan test
# 必要に応じて --testsuite=Unit / Integration / Feature で個別実行
docker compose build
```

Larastanは `phpstan.neon.dist` のlevel 5でapp / bootstrap / config / database / routesを解析します。既存エラーを無視するbaselineは設けていません。作業ルールは [AGENTS.md](AGENTS.md)、テスト方針は [testing strategy](docs/testing/strategy.md) を参照してください。

## JV-Link 開催スケジュール

Windows Collectorから認証付き内部APIを通じて開発DBへ開催スケジュールを取り込みます。[Collectorの起動手順](collector/README.md)、[API契約と公式仕様の対応](docs/architecture/jvlink-schedules.md)、[Issue #4の検証結果](docs/testing/issue-4-verification.md)を参照してください。CIではLinux/Windowsで合成データの.NET 10テストとWindows CLIのbuildを実行します。
