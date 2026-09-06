# Issue #30 検証記録

この文書は、KD3取込の物理仕様・改版対応・全量性能を再設計する Issue #30 / PR #31 について、**2026-09-06時点で実データまたは公式仕様から確認できた事実だけ**を記録する。

未検証のlayout候補や将来の設計案は、確定事項と混同しない。

## 全量再取込で確認した問題

2026-09-06の全量再取込では、保存済み `source_files` 12,256件に対し約 `0.69 files/s`、ETA約5時間となった。最初の50 source内でJBに複数の `field_validation` / `physical_layout` が発生したため、全量実行は停止して設計を再監査する方針とした。

現時点の保存済みsource件数は以下。

| artifact | source数 |
| --- | ---: |
| HB | 2,044 |
| IB | 2,042 |
| JB | 2,043 |
| KD | 2,042 |
| LB | 2,043 |
| MB | 2,042 |
| 合計 | 12,256 |

今回のspeed/result schema refreshに必要な主対象はHB/IBであり、全artifactを一律replayする必要はない。

## 公式KD3仕様書から確認した事実

参照仕様は `KD3データ仕様書.xlsx` の2020-06-26発表版および改版履歴。

- KD3第3世代は2007-10-01開始。
- 2007-10-10に予想オッズ2・確定オッズ2・確定オッズ3の物理仕様修正がある。
  - `kol_ods2`: 予備フィールド 92→1677、馬単オッズ 7→5桁。
  - `kol_kod2`: 予備フィールドのサイズ変更。
  - `kol_kod3`: 予備フィールド変更と後続address修正。
- 確定オッズは2007-10-13分から1ファイル1日に変更。
- 予想オッズには前日発売レースが含まれ得る。JB artifact日とrecordのrace dateが翌日になる正当ケースがある。
- 2019-05-28版ではリステッド・何勝クラス追加に伴い複数ファイルの予備領域が変更され、実提供は2020年秋頃予定と記載されている。

2007-10-10より前のrecord length / offsetは、改版差分から候補を復元できるが、**実sourceでの確認が終わるまでは確定値としない**。

## JBの日付検証

旧parserはJBのrecord dateをartifact日と同日に限定していたため、前日発売のG1等を誤って `field_validation` にしていた。

PR #31のparser 1.2.0で以下を再parseした。

| source_file | artifact date | 結果 | records |
| ---: | --- | --- | ---: |
| 12787 | 2007-10-13 | succeeded | 50 |
| 12827 | 2007-10-20 | succeeded | 74 |
| 12811 | 2007-10-27 | succeeded | 74 |

したがって、これらの旧 `field_validation` は、JBで翌日レースを許可していなかったdate policyの誤判定だったことを確認した。

現行ルールはJBの `kol_ods.kd3` / `kol_ods2.kd3` に限り、artifact日と正確に翌日のrecord dateを許可する。任意の未来日を許可しない。

## source_file 12821 の物理layout調査

`source_file=12821`（JB、2007-10-21）はparser 1.2.0でも次のエラーになった。

```text
physical_layout
internal file: kol_ods2.kd3
```

LZHを実際に展開して確認した結果:

```text
kol_ods.kd3   54,144 bytes
kol_ods2.kd3 325,549 bytes
```

`kol_ods2.kd3` は36 recordsで、CRLF単位のrecord length分布は以下だった。

```text
35 records: 9043 bytes
 1 record : 9044 bytes
```

9044 bytesなのはrecord 11だけ。

```text
record=11
byte_offset=90430
body_length=9042
```

末尾は正常な `CRLF (0d 0a)` であり、改行が3 bytesになっているわけではない。

さらにrecord 11を現行仕様のoffsetで検査すると:

```text
normal_offsets exacta=306/306 trio=816/816 total=1122/1122
shifted_+1    exacta=277/306 trio=346/816 total=623/1122
```

すなわち、馬単開始offset 1799、3連複開始offset 3329は**ずれておらず、全1122 slotが現行layoutで成立する**。

本文の期待長は9041 bytesだが実際は9042 bytesで、末尾の追加1 byteはspaceだった。したがってrecord 11は別layoutではなく、実質次の形であることを確認した。

```text
[9041-byteの正常payload][余分なspace 1 byte][CRLF]
```

この実データにより、`filesize % record_length == 0` をファイル全体のlayout判定条件にする設計は不適切と確定した。

### ここから確定できること

- 12821の`kol_ods2`は2007初期8070-byte layoutではない。
- 12821の通常recordは9043 bytes世代。
- record 11のfield offsetは現行9043-byte layoutと一致する。
- 9044 bytesを独立したlayoutとして登録すべきではない。
- file全体のsize divisibilityだけでlayoutを選択してはいけない。

### まだ確定していないこと

- 2007-10-10より前の`kol_ods2`で8070-byte候補が実在するか。
- 9044-byte trailing-space異常が12821 record 11だけか、他sourceにも存在するか。
- `kol_kod2` 9050-byte候補、`kol_kod3` 49124-byte候補が実sourceに存在するか。
- 2019/2020改版前後で、現行offsetのまま安全にdecodeできる範囲。

全raw sourceを対象に物理format auditを行うまで、これらを確定扱いしない。

## Parser設計への確定した影響

現行の次の流れは変更が必要。

```text
extracted file size
  → record lengthで割り切れるlayoutを選択
  → fixed-size fread
```

実データに合わせるには、少なくともrecord境界とphysical validationを分離する必要がある。

```text
LZH展開
  → CRLFでrecord境界を検出
  → record単位の実長を確認
  → known layout candidateとfield validityを照合
  → 明示的に確認済みの物理異常だけ正規化
  → raw byte slice / typed decode
```

ただし、`9044なら末尾を1byte削る` のような一般ルールにはしない。全source監査で同型が確認され、かつ期待payloadが完全に成立するケースだけを許容する。

## Performanceで確認したこと

旧/現行import経路では、主な性能問題は単純なindex不足ではなくSQL/write shapeにある。

- `EntityResolver` は既知entity解決でも複数SELECT / row lock / `last_seen_at` UPDATEを繰り返す。
- `RaceResolver` もvenue/calendar/race/source identifierを行単位で解決・更新する。
- HB importではtouched raceごとにspeed statistics/metricsを逐次再計算する。
- parserはPR #31でarchiveのtemp copy・size・SHA-256計算を1 stream passへ改善済み。

artifact別の旧計測値は参考値として以下。

| artifact | 平均 |
| --- | ---: |
| HB | 約3.59秒/source |
| IB | 約1.72秒/source |
| JB | 約0.36秒/source |
| KD | 約0.80秒/source |
| LB | 約0.27秒/source |
| MB | 約0.30秒/source |

今後はresolverのsource transaction境界付きbulk化、speed derived計算のset-based化またはraw ingestionからの分離を優先する。

## Odds domain設計は未確定

現在の`race_odds`は物理配列の最大combinationをdomain rowへ展開するため、全期間では非常に大きくなる可能性がある。現時点のDBでも約437万rowに達した時点がある。

blank / `-` / `*` の意味と、runner数に対して構造的に存在しないcombinationを、公式仕様と全実データで再監査する。

したがって、現時点では「全physical slotを必ず1 domain rowとして永続化する」ことを最終設計とはしない。

## 全量再取込を再開する条件

少なくとも以下を終えるまで、19年分12,256 sourceの全量importは再開しない。

1. 全raw sourceまたは代表世代を用いたphysical format audit。
2. record境界 / layout選択 / known anomalyのparser設計確定。
3. HB/IB resolver N+1の削減。
4. speed derived計算のbulk化または分離。
5. odds persistence方針の確認。
6. 2007-10を含むtargeted regression。
7. 数か月分のHB/IB benchmarkで改善を実測。

この文書は、追加の実データ監査結果が得られるたびに更新する。
