# Issue #7 KD3 Domain Import 検証記録

## Synthetic

PostgreSQL `keiba_test` と合成fixed-width recordのみを使用し、Migration、Parser repeated extraction、identity/race resolve、hb/ib/jb/mb mapping、sei3 optional、history unresolved、speed calculation、odds special value、冪等性、stale protection、rollback audit、主要JOINを確認した。実データ・有償仕様本文・秘密情報はCIへ渡さない。

## Private regression

2026-09-05、Git管理外の保存済みartifactを `keiba_dev` のみに取り込んだ。原本・path・SHA・外部識別子・本文は記録しない。

| Artifact | Parser input | First import | Second import |
| --- | ---: | --- | --- |
| hb | den1 36 / den2 455 / uma 455 | 成功、36 races / 455 runners / 455 snapshots / 1,144 workouts / 2,275 speed slots / 3,946 deduplicated histories | 8,311 unchanged、duplicateなし |
| jb | ods 36 / ods2 36 | 成功、47,844 market combinations | 47,844 unchanged、duplicateなし |
| mb | com1 409 | 成功、868 nonblank typed comments、359 blank comments skipped | 868 unchanged、duplicateなし |

hbではspeed statistics 180行、non-null raw speedに対するmetrics 1,572行を生成した。source lineageは全current/snapshot rowに保持され、identity mapping conflictは発生しなかった。batch odds再実行も正常終了した。

private storageに存在しなかった ib / kd / lb と過去年代sampleは実データ未検証で、synthetic testで契約をカバーする。outlier thresholdは仕様どおり未設定である。
