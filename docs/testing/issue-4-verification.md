# Issue #4 検証記録

実施日: 2026-09-05。Windows JV-Link + .NET SDK 10.0.400 x86、WSL2 Ubuntu 26.04、Docker Compose、PHP 8.5 / Laravel 13 / PostgreSQL 18。

## 実JV-Link → Collector → Laravel → dev PostgreSQL

1. `codex/issue-4-jvlink-schedules` で実装。Windowsユーザー領域へ公式.NET 10 x86 SDKを導入し、登録済み32bit COMを確認。
2. Composeを起動、既存Migrationをdevへ適用。ローカル専用tokenを生成し、Laravel `.env` とCollector環境変数に設定（秘密値は記録しない）。
3. `YSCH / option=1 / fromtime=20260801000000` で実CLIを実行。`JVOpen=0, files=1`、YS 291レコード、自然キー291件、`JVClose=0`。
4. 初回API結果: `received=291, inserted=291, updated=0, unchanged=0`。
5. 同じfromtimeで再取得・再送: `received=291, inserted=0, updated=291, unchanged=0`。新しいcaptured_atによりlast_seen_atが進むためupdatedとなる。
6. 再送後のdev DB: `venues=10`、JV-Link venue mapping=10、`race_calendars=291`、自然キーdistinct=291。source_updated_at非null件数=0（YSに時刻はない）。
7. 別の32bit PowerShell COM呼出しで元YSを読み直し、開催日・競馬場コード・開催回・開催日目の4項目をDBのjoin結果と比較。**一致1件を確認**。一時照合資料はリポジトリ外で扱い、実payload・生レコードはcommitしない。

補足: `fromtime=20260901000000` ではJVOpen=-1（該当配信なし）。取得開始を8月1日にしたところ取得できた。fromtimeは開催日ではない。

JVRead文字列全体をCP932へ戻す初期実装ではEncoderFallbackExceptionが発生した。必要項目がある先頭26文字のASCII headerだけを検証・解析する構成へ修正し、実CLI取得・再送に成功。戻り値の原本バイト数382と末尾CRLFは検証する。不要な日本語領域の変換に依存しない回帰テストも追加。

## ローカル自動検証

| 検証 | 結果 |
| --- | --- |
| `docker compose build` | 成功 |
| `docker compose up -d --wait` / `ps` | app・web・dev/test PostgreSQLが起動 |
| `curl --fail http://localhost:8080/health` | HTTP 200、status=ok |
| `composer validate --strict` | 成功 |
| `composer lint` | 成功 |
| `composer analyse` | 成功、Larastanエラーなし |
| `php artisan migrate:fresh --env=testing --no-interaction` | keiba_testのみ再構築成功 |
| `php artisan test` | 66 tests / 180 assertions成功 |
| .NET 10 synthetic tests | 27 tests成功 |
| Windows x86 CLI Release build | 成功、警告0・エラー0、vendor DLL参照なし |

Laravelテストで同一captured_atの完全再送はunchanged、変更時のfirst_seen保持・last_seen更新、古いsource timestampの拒否、未知mapping作成・同名venue再利用・孤立mapping/名称競合409・batch rollbackを検証。既存DB safety guardの全テストも成功。

通常CIには実JV-Link・実データ・秘密値を入れない。GitHub ActionsではPHP品質/テスト、Docker起動/テスト、Linux/Windowsの.NETテスト、Windows x86 CLI buildを実行する。CIの実行URLと最終結果はPRに記録する。

## 未検証・対象外

- 別JV-Link版、64bit COM、別ユーザーの契約設定。
- 本番接続、外部ネットワークへのdeploy、負荷/性能試験。
- 永続Outbox・自動再送・当日速報・オッズ取得（Issue #8以降）。
- 元YSの競走名など、開催snapshotで使用しない領域の解析。

手順・API契約・仕様根拠は [Collector README](../../collector/README.md) と [architecture](../architecture/jvlink-schedules.md) を参照。
