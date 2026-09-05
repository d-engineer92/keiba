# KD3 Downloader 検証記録（Issue #5）

2026-09-05、`docs/architecture/data-flow.md` の確認済みHTTP contractに従い、資格情報をローカル `.env` だけから読み込んで実施した。ID/password、Cookie、response本文、実SHAはログ・文書・Gitに記録していない。

## Synthetic / local

一時展開した PHP 8.5.4（zip / pdo_pgsql等）と、test専用の PostgreSQL 18.6（UTC、`keiba_test`）を使用した。Docker daemon socketの権限がないため Docker build / Compose起動はローカル未実施で、CI確認対象とする。

| 検証 | 結果 |
| --- | --- |
| `composer validate --strict` | 成功 |
| `composer lint` | 成功 |
| `composer analyse` | 成功、0 errors |
| 空の `keiba_test` への全Migration | 成功 |
| Unit | 17 tests / 19 assertions 成功 |
| Integration | 20 tests / 59 assertions 成功 |
| Feature | 49 tests / 146 assertions 成功 |

## 実サイト E2E

test DBとは別プロセスの一時 `keiba_dev` に、確認用の `race_calendars.race_date=2026-09-05` を登録して `php artisan kd3:download --date=2026-09-05` を2回実行した。

- login成功後、日次bundleは HTTP 200 / application/zip / Content-Dispositionあり / PK signature だった。
- 未掲載日の実responseは HTTP 200 / text/html / Content-Dispositionなし / `データなし` だった。
- 1回目は公開済み `hb`, `jb`, `mb` のLZH原本をprivate diskに保存した。`ib`, `kd`, `lb` は同bundleに存在せず、各statusを `not_available` とした。
- 3ファイルすべてで保存bytesから再計算したSHA-256・sizeがDB metadataと一致した。
- 2回目も `source_files` は3versionのままで、全6 statusの `attempt_count` は2になった。成功3typeのlatest pointerを維持した。
- 実データ本文・一時ZIPはリポジトリへ追加していない。LZHは `storage/app/private/kd3/raw/` 配下（Git ignore対象）に保存した。

## Issue #6 private parser regression

2026-09-05にGit管理外の保存済み `hb` / `jb` / `mb` LZHを現在のParser APIで再検証し、本文・保存先を記録せずに確認した。size/SHA-256、LZH事前entry検査、expected internal file set、canonical record length、CRLF、typed主要field、artifact date（commentは過去走日付を許容）、hb cross-file validationが成功した。件数はhbがden1 36・den2 455・uma 455（合計946）、jbがods 36・ods2 36（合計72）、mbがcom1 409だった。

未検証は、実サイトの401/403/404/410/5xx実応答、接続断、同名再発行の実例、local Docker image build / Compose、object storage。これらの分類・versioningはsynthetic testで検証した。
