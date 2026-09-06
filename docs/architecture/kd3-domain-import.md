# KD3 Domain Import

> [!IMPORTANT]
> Issue #30でKD3の物理仕様・Parser・Domain persistenceを再監査中。特にoddsの「全physical slotをそのまま1 domain rowへ保存する」設計と、raw ingestion中のderived計算は最終設計ではない。確定済み/未確定の区別は [Issue #30 検証記録](../testing/issue-30-verification.md) を参照。

## Boundary

`Kd3DomainImporter` は `Kd3Parser` が返す `Kd3ParsedPackage / Kd3ParsedRecord` だけを入力とし、LZH・一時path・raw recordを再decodeしない。`Kd3LayoutRegistry` のscalar fieldとrepeated groupから、調教、speed 1..5、odds market配列等をtyped valueへ変換する。`kol_uma.kd3` の直近5走・6〜55走ブロックはcanonicalで使用しないためdecodeしない。

raw physical layoutとdomain persistenceは別責務とする。Parserが全slotを安全にdecodeできても、それを全て永続化すべきとは限らない。

## Resolve and transaction

1. horse / jockey / trainer codeを `source_identifiers` で内部entityへ解決する。
2. KD3 venue codeはcanonical venue名で既存venueを再利用し、mappingを追加する。
3. `race_date + venue_id` のcalendarを解決・作成し、meeting値はnullだけ補完する。non-null conflictは失敗する。
4. `race_calendar_id + race_no` のraceを解決・作成し、KD3 race keyをmappingする。
5. artifact mapperがdomain natural keyへupsertする。
6. speed indexの参照をcanonical resultから再構築し、statistics/metricsを計算する。

domain mutation全体はsource file単位のPostgreSQL transactionとadvisory lockで直列化する。失敗時はdomain rowをrollbackした後、transaction外の `kd3_import_runs` をfailedへ更新する。

ただし現状はresolver N+1とspeed derived計算がraw ingestionのクリティカルパスにあり、全量性能上の主要課題になっている。再設計ではsource transaction境界付きbulk resolutionと、derived rebuildのset-based化または別phase化を優先する。

## Artifact mapping

| Artifact | Input | Domain |
| --- | --- | --- |
| hb | den1 / den2 / uma | entries, runners, entry snapshots, workouts, speed source facts |
| ib | sei1 / sei2 / optional sei3 / uma | results, runners, result snapshots, optional sanctions |
| jb | ods / ods2 | forecast odds（persistence方式は再設計中） |
| kd | kod / kod2 / kod3 | final odds（persistence方式は再設計中） |
| lb / mb | com1 | typed comments; unresolved race remains nullable |

## Lineage and stale protection

同一source IDの再実行は既存自然キーをunchangedとして数える。異なるversionでは `source_files.downloaded_at`、同時刻はidを比較し、新しいsourceだけがcurrent rowを更新する。entry/resultは親rowをロックしてaggregate全体のfreshnessを先に判定する。古いsourceではrunner以下を丸ごとskipし、新しいsourceでは受信したhorse/workout/speed slotの自然キー集合との差分をFK順に削除するため、削除済みchildを残したりstale sourceで復活させたりしない。

speed referenceとcommentのnullable race referenceは派生参照として再解決可能にする。speedのsource factは `runner_speed_indices` に保持し、参照先は `runner_speed_index_references` としてcanonical resultからresolver version付きで再構築する。speed referenceは候補からtarget直前までの中央開催日に現行versionのIB成功取込が連続している場合だけ作る。同一immutable sourceの再実行では、新設classification / cancellation type / speed index正規化のsource factだけを明示的にrefreshする。commentは未解決raceが後からcanonical化された場合にrace referenceだけを更新する。

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
| odds source value/status | ods, ods2, kod, kod2, kod3 / market arrays |
| comment type/text | com1 / connections, next-race memo, previous comment |

## Odds contract（再設計中）

KD3のodds fieldには数値以外の特殊値が存在するため、0へ潰さず意味を保持する。現行normalizerはblank / `-` / `*` 等をstatusへ変換している。

一方、物理レイアウトは18頭を上限とした多数のcombination slotを持つ。これらのうち、blank slotや実際のrunner数では成立しないselectionまで1 rowとして永続化すると、全期間で非常に大きな `race_odds` になる。実DBでは早期のhistorical import途中でも約437万rowに達した時点がある。

したがって以下は未確定であり、公式仕様と全raw sourceを突合して決める。

- blankが「missingというdomain fact」なのか、単なる未使用physical slotなのか。
- `-` / `*` 等の特殊値をどのstatusとして保持するか。
- runner数・枠数から構造的に成立しないcombinationを保存対象外にできるか。
- forecast/finalで同じpersistence ruleを使えるか。

現時点では「全physical combinationを必ずdomain rowへ保存する」を設計原則にしない。

## Speed contract

KD3 `kol_den2` のspeed 1..5はsource factとして `runner_speed_indices` に保持し、raw整数表現は0.1単位へ正規化する。参照raceは別のderived relationとして `runner_speed_index_references` に保持する。

参照候補は同一horseの過去中央平地から解決し、取消・除外・競走中止、地方、障害、外国等を候補に入れない。失格・降着・繰上げでもspeed source factが存在するケースはslotを消費する。参照の完全性を保証できない場合はsource speed自体を捨てず、referenceをnullのままにする。

comparison scopeは同一target race・同一central-flat run-back。mean、median、population stddev、min/max、MADを計算する。variance 0はz/deviationをnull、MAD 0はrobust値をnullとする。outlier ruleは未合意のため `is_outlier` とrule versionはnull、excluded countは0である。

speed statistics/metricsの計算自体はsource factではなくderivedであり、raw ingestion中にraceごと・slotごとに逐次upsertする現実装は性能再設計の対象とする。
