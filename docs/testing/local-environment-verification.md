# Issue #1 開発基盤の検証記録

## 2026-09-05 Docker build 修正後の実機検証

Docker Engine 29.8.0 / Docker Compose v5.5.1 / WSL2 Ubuntu 26.04 で実行。以下は Docker コンテナで確認した結果であり、後述の初回検証時の「Docker未確認」を更新する。

### PHP拡張の修正

`docker run --rm php:8.5-fpm-bookworm php -m` でベースイメージを確認した。

- PHP: 8.5.10
- ベースイメージ digest: `sha256:81b9c405b013ebda0c9b8cd7a1a61424cf3627ca96348d93752a9b0539ce9a25`
- 標準で有効: `mbstring`, `curl`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `SimpleXML`, `PDO`, `fileinfo`, `ctype`, `tokenizer`, `Phar` 等
- 追加ビルド: PostgreSQL 接続に必要な `pdo_pgsql` のみ

既に有効な `dom` の再ビルドが、`lexbor/html/parser.h` 不在のエラーを起こしていた。`mbstring`, `curl`, `dom`, `xml`, `xmlwriter` の再ビルドと、それらのビルド用パッケージを削除した。現時点のアプリ・依存パッケージで要求していない `zip`, `bcmath`, `pcntl` も追加しない。Composer のアーカイブ展開には `unzip` を使用する。将来これらの拡張を必要とする処理を導入する際に追加する。

公式のビルド構成は [docker-library/php の Dockerfile](https://github.com/docker-library/php/blob/master/8.5/bookworm/fpm/Dockerfile) も参照した。

### 実行順と結果

以下の順序で実行し、全コマンドが成功した。

```bash
docker compose build --no-cache
docker compose up -d
docker compose ps
curl --fail http://localhost:8080/health
curl --fail http://localhost:8080/
curl --fail http://localhost:8080/up
docker compose exec -T app php artisan test
```

| 検証 | 結果 |
| --- | --- |
| キャッシュなしビルド | 成功、`pdo_pgsql` のみ追加ビルド |
| `up -d` / `ps` | app / postgres / postgres-test は healthy、web は Up |
| HTTP `/health` | 200、`{"status":"ok"}`（Laravel → PostgreSQL 接続成功） |
| HTTP `/` / `/up` | いずれも 200 |
| `php artisan test` | **13 passed / 27 assertions**（0.81秒） |
| `composer check-platform-reqs` | 全依存パッケージの要件を充足 |
| app の `php -m` | ベースイメージの拡張に `pdo_pgsql` が追加されたことを確認 |
| app の `id` | UID/GID 1000、非rootで稼働 |
| dev `php artisan migrate:fresh` | 新規作成されたdev DBに全3 Migration成功 |
| test `php artisan migrate:fresh --env=testing` | test DBの全3 Migration成功 |
| dev `php artisan migrate:status` | 全3件が Ran |

Web は `127.0.0.1:8080` で公開し、4サービスを起動した状態で作業を終了。コンテナ再作成後のデータ保持と任意のperfプロファイルは今回の追加検証には含まない。

Dockerグループ加入前に開始したCodexセッションではソケットへの接続が拒否されたため、`wsl.exe -d Ubuntu-26.04 -u dkuro --cd /home/dkuro/keiba --exec ...` で同じユーザーの新規WSLセッションからコマンドを実行した。ホスト権限やソケットのパーミッションは変更していない。

## 2026-09-05 初回検証（Docker導入前の記録）

Issue #1、ADR-001、architecture/overview.md、database/logical-design.md、testing/strategy.md に従って実装。

検証ホストは WSL2 Ubuntu 26.04。Docker Engine が未導入で `/var/run/docker.sock` が存在せず、sudo は対話認証が必要だったため、Docker コンテナのビルド・実起動は未確認。

代替として Ubuntu パッケージを `/tmp` に展開し、PHP 8.5.4 / PostgreSQL 18.6 / Nginx 1.28.3 をユーザー権限で起動した。システムへのパッケージインストールは行っていない。名前解決・パスの変更は一時的な mount namespace 内のみ。dev/test は独立した PostgreSQL プロセスとデータディレクトリを使用し、dev ポートのみ 5433 に変更した。これらは Docker での検証を代替する完了証拠ではない。

| 検証 | 結果 |
| --- | --- |
| Compose v2.40.3 `config --quiet`（通常・perf profile） | 成功 |
| `composer install` / `composer validate --strict` | 成功、composer.lock を保存 |
| dev `php artisan migrate:fresh` | 全3 Migration 成功 |
| test `php artisan migrate:fresh --env=testing` | 全3 Migration 成功 |
| `php artisan test` | 13 tests / 27 assertions 成功 |
| dev 接続情報を外部環境変数に設定したテスト | test DB 使用、成功 |
| dev に確認用レコードを置いて test 実行前後を比較 | 内容一致（検証後に確認用レコードを削除） |
| test Migration rollback → 再適用 | 成功 |
| `DB_HOST=postgres` を渡した `migrate:fresh --env=testing` | DB操作前に拒否 |
| dev config cache が残った状態でテスト | DB操作前に拒否（検証後にcache削除） |
| Nginx → PHP-FPM → Laravel → PostgreSQL `/health` | HTTP 200、`{"status":"ok"}` |
| Nginx 経由 `/` と `/up` | HTTP 200 |
| `vendor/bin/pint --test` / `git diff --check` / entrypoint `sh -n` | 成功 |
| Compose `up -d` | Docker デーモンに接続できず未検証 |

HTTP検証では Nginx のサイト設定から listen ポートだけを 18080 に変え、PHP-FPM と PostgreSQL は上記のネイティブプロセスを使用した。Docker 内の非root UID/GID、イメージのビルド、サービス起動順、named volume の再作成後保持は実環境での追加確認が必要。

## 再確認・永続化確認の手順

[README](../../README.md) の初回起動手順を実行したうえで、以下を確認する。

```bash
docker compose ps
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate:fresh --env=testing
docker compose exec app php artisan test
curl --fail http://localhost:8080/health

# コンテナを再作成しても Migration 履歴・データが残ること
docker compose down
docker compose up -d
docker compose exec app php artisan migrate:status
```

dev の `migrate:fresh` は開発データを削除するため、初期状態または破棄可能なデータで実行する。perf は `--profile perf` と `.env.perf` を使い、同一 Migration の適用を確認する。

技術仕様の参照: [Laravel 13 リリース](https://laravel.com/docs/13.x/releases)、[PostgreSQL 公式イメージ（18以降のvolume配置）](https://hub.docker.com/_/postgres)。
