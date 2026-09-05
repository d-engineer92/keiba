# Issue #8 検証記録

実施日: 2026-09-05。

## Synthetic

- Windows x86 .NET 10: 45 tests 全成功。O1 historical/realtimeと単勝`0999`通常値/`9999`上限値、AV取消/除外、JC騎手変更、WH馬体重、WE天候馬場、deterministic cycle ID、SQLite reopen/idempotency、race-key coverageと再開、SQLite→Laravel backfill report契約、remote成功後local ack前crash相当の再送、canonical未登録のpending維持、retry/dead分類、2008-01-01 planner境界を確認。
- Windows x86 CLI Release build: 警告0、error 0、vendor DLL参照なし。
- Laravel: Composer strict validate、Pint（100 files）、Larastan（error 0）、空の `keiba_test` への全Migration、124 tests / 415 assertionsが成功。PR CIでも最終確認する。

fixtureはすべて合成byte / normalized JSON。実payload、原本、token、SQLite pathをGitへ含めない。

## Private JV-Link observation

32bit登録済み JV-Link 1.1.8.0 で `JVRTOpen` を実行した。

| 項目 | 結果 |
| --- | --- |
| `0B31` 速報単複 | result=0、O1 1 recordを観測、length/parser通過 |
| `0B11` 馬体重/天候 | result=0、1 recordを観測、length/parser通過 |
| `0B14` 開催変更一括 | result=0、19 recordsを観測。0B31/0B11と同一STA sessionで逐次取得 |
| `0B41` historical | result=0、直近1 raceでO1 174 recordsを取得。deterministic duplicate 1件を除く173 snapshotsをrepo外SQLiteへpending commit |
| 最古取得観測日 | 2008-01-05。境界直後の開催日候補 `200801050601` を限定照会し、result=0、O1 36 recordsを観測 |

live collect全体では30 normalized eventsをOutboxへcommitした。event内訳としてrunner status 3件、jockey change 1件を観測したが、実payload値は記録しない。

## Crash / recovery E2E

SQLite reopen、pending保持、duplicate enqueue、HTTP分類はsyntheticで確認済み。実historical 173件とlive 30件を同じprivate Outboxへcommitし、flushで199件をdev PostgreSQLへ保存した。レビュー修正前は、開催日一括に含まれた別raceのrunner status 3件 / jockey change 1件がcanonical race未作成の409でdeadになり、この観測を契機に`canonical_dependency_missing`をpending維持するよう修正した。

sent済み1件を「API成功後、SQLite sent更新前にcrash」の状態としてpendingへ戻し、同一eventを再flushした。Laravelはsame ID / same hashをunchangedとして受け、Outboxはsentへ復帰し、PostgreSQL重複は発生しなかった。API停止中の実network failure自体はsyntheticで検証し、private E2Eでは実施していない。PR CIは実JV-Link・有償dataを使用しない。

レビュー修正後、2008-01-05の上記1raceと最新側 `202609050101` を再照会し、後者はO1 174 records / deterministic duplicate除外後173 pendingを観測した。さらに2008-01-05の1日範囲を120 race keyでbackfillし、24 raceでdata、96 raceでno-data、計120件のrace-key coverageをSQLiteからdev PostgreSQLへ`odds coverage sync`できた。repo外の一時SQLiteは削除済み。2008-01-01自体は非開催日のため「2008-01-01から全日取得を保証」する結果ではなく、今回の契約・実機で要求境界直後の開催日を取得できたという観測に限る。
