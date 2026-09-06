# KD3 Domain Import

## Boundary

`Kd3DomainImporter` は Issue #6 の `Kd3Parser` が返す `Kd3ParsedPackage / Kd3ParsedRecord` だけを入力とし、LZH・一時path・raw recordを再decodeしない。`Kd3LayoutRegistry` のscalar fieldとrepeated groupが調教、speed 1..5、全odds combinationをbyte offsetからtyped valueへ変換する。`kol_uma.kd3` の直近5走・6〜55走ブロックはcanonicalで使用しないためdecodeしない。

## Resolve and transaction

1. horse / jockey / trainer codeを `source_identifiers` で内部entityへ解決する。
2. KD3 venue codeはcanonical venue名で既存venueを再利用し、mappingを追加する。
3. `race_date + venue_id` のcalendarを解決・作成し、meeting値はnullだけ補完する。non-null conflictは失敗する。
4. `race_calendar_id + race_no` のraceを解決・作成し、KD3 race keyをmappingする。
5. artifact mapperがdomain natural keyへupsertする。
6. speed indexの参照をcanonical resultから再構築し、touched raceのstatistics/metricsを再計算する。

domain mutation全体はsource file単位のPostgreSQL transactionとadvisory lockで直列化する。失敗時はdomain rowをrollbackした後、transaction外の `kd3_import_runs` をfailedへ更新する。

## Artifact mapping

| Artifact | Input | Domain |
| --- | --- | --- |
| hb | den1 / den2 / uma | entries, runners, entry snapshots, workouts, speed raw/statistics/metrics |
| ib | sei1 / sei2 / optional sei3 / uma | results, runners, result snapshots, optional sanctions |
| jb | ods / ods2 | forecast odds |
| kd | kod / kod2 / kod3 | final odds |
| lb / mb | com1 | typed comments; unresolved race remains nullable |

## Lineage and stale protection

同一source IDの再実行は既存自然キーをunchangedとして数える。異なるversionでは `source_files.downloaded_at`、同時刻はidを比較し、新しいsourceだけがcurrent rowを更新する。entry/resultは親rowをロックしてaggregate全体のfreshnessを先に判定する。古いsourceではrunner以下を丸ごとskipし、新しいsourceでは受信したhorse/workout/speed slotの自然キー集合との差分をFK順に削除するため、削除済みchildを残したりstale sourceで復活させたりしない。oddsもrace×phase×market単位で集合差分を反映してから500行ずつbatch upsertする。

speed referenceとcommentのnullable race referenceは派生参照として再解決可能にする。speedのsource factは `runner_speed_indices` に保持し、参照先は `runner_speed_index_references` としてcanonical resultからresolver version付きで全件再構築する。speed referenceは候補からtarget直前までの中央開催日に現行versionのIB成功取込が連続している場合だけ作る。同一immutable sourceの再実行では、新設classification / cancellation type / speed index正規化のsource factだけを明示的にrefreshする。commentは未解決raceが後からcanonical化された場合にrace referenceだけを更新する。

## Mapping matrix

| DB column | Internal file / field |
| --- | --- |
| race identity | all race files / venue, year, meeting, day, race no, date |
| `race_entries.race_name`, start, surface/course, distance, grade/class/age/weight, count | den1 / confirmed race fields |
| entry runner horse/frame/no, weight, jockey, trainer, mark | den2 / confirmed runner fields |
| `runner_workouts.*` | den2 / three 117-byte workout groups |
| `runner_speed_indices.speed_index` | den2 / central flat 5-before through previous-run slots |
| horse snapshot name, sex, birth date, color, breed, trainer | uma / identity fields |
| result category/discipline/status/weather/track/pace/count | sei1 / race result fields |
| result runner finish/time/margin/passing/last3F/body/odds/popularity | sei2 / runner result fields |
| sanction description | optional sei3 / sanction text |
| odds value/range/status | ods, ods2, kod, kod2, kod3 / market arrays |
| comment type/text | com1 / connections, next-race memo, previous comment |

blank/special oddsは0にせず、missing / cancelled / not_offered / above_limitを `status` に保存する。unordered marketはselection昇順、ordered marketは提供順を維持する。

## Speed contract

comparison scopeは同一target race・同一central-flat run-back。mean、median、population stddev、min/max、MADを計算する。variance 0はz/deviationをnull、MAD 0はrobust値をnullとする。outlier ruleは未合意のため `is_outlier` とrule versionはnull、excluded countは0である。
