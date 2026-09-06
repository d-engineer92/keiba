# KD3 Speed Index Reference Design

## Principle

`kol_den2.kd3` の「スピード指数 中央平地5走前〜前走」は source fact として保存し、どの過去レースに対応するかは canonical result から導出する。

誤ったレースへ紐付けることを最も重大な障害とみなし、候補を一意に確定できない場合は **未解決（reference rowなし）** とする。`kol_uma.kd3` の過去5走/6〜55走スナップショットを reference 解決に使用しない。

## Ground truth verified against KeibaBook UI

競馬ブック会員画面の S指数とKD3の5スロットを手動照合し、以下を確認した。

| Case | Observed behavior |
| --- | --- |
| ウオッカ 2007-11-11 出走取消 | S指数なし。次走のKD3 5値は完全据え置き。slotを消費しない |
| キングオブハイシー 2007-10-20 失格 | S指数67.7。次走で67.7が前走へ入り1slotシフト |
| パルエクスプレス 2007-11-25 競走中止 | S指数なし。次走のKD3 5値は完全据え置き |
| エイシンデピュティ 2007-10-28 降着 | S指数89.0。次走で前走として採用 |
| ピサノデイラニ 2007-10-14 繰上げ | S指数95.0。次走で前走として採用 |
| レオアスカ | 障害走と地方走を飛ばし、中央平地のみがKD3 5slotに並ぶ |
| ウオッカ 2008-03-29 UAE | 外国走でS指数なし。KD3仕様の「中央平地」定義上 reference 対象外 |

現在の実データでは status 31/33/34/35 は finish time がなく、32/36/37 は finish time が存在した。正常中央平地でS指数が付かない例は確認範囲では見つかっていない。

## Eligible reference result

resolver v1.1.0 は過去の `race_result_runners` から以下をすべて満たすものだけを候補にする。

1. 同一 horse
2. target race より前の日付
3. `race_results.source_category_code = '0'`（中央）
4. `race_results.discipline_code = '0'`（平地）
5. `finish_time_tenths IS NOT NULL`
6. `finish_status_code IS NULL OR finish_status_code IN ('32', '36', '37')`

日付降順、race_no降順で最大5件を取り、1〜5を `central_flat_run_back` に対応させる。

- 31 落馬: v1では対象外
- 32 失格: 実走タイムがあれば対象
- 33 中止: 対象外
- 34 取消: 対象外
- 35 除外: 対象外
- 36 降着: 実走タイムがあれば対象
- 37 繰上げ: 実走タイムがあれば対象

未知のstatusは安全側に倒して対象外とし、誤referenceを作らない。

## Coverage safety

候補が中央平地の有効実走であっても、候補日からtarget前日までの中央開催日にIB取込の穴が1日でもある場合、そのreferenceは作らない。途中の1走欠損によって2走前以降が1slotずつずれる事故を防ぐためである。

resolver v1.1.0 は `race_calendars` を中央開催日の期待集合として使い、各日について `kd3_artifact_statuses.latest_source_file_id` がその日の最新IBを指し、そのsourceに現行 `parser_version` / `importer_version` / `spec_version` の成功した `kd3_import_runs` があることを要求する。候補日自身もcoverage済みでなければならない。target当日のIBはまだ結果前でよいため要求しない。

この判定はfail-closedであり、coverageが不足している場合は誤ったFKを推測せずreference rowなしとする。したがって、referenceを完全なものとして扱う前提として中央の `race_calendars` 自体も全期間backfill済みである必要がある。

## Tables

### `runner_speed_indices`

KD3 source value only.

- `race_entry_runner_id`
- `central_flat_run_back` (1..5)
- `speed_index` NOT NULL
- `source_file_id`
- `source_record_number`

固定長ファイル上のblank slotは行として保存しない。KD3仕様は0.1単位なので、raw `867` は `86.7` として保存する。

### `runner_speed_index_references`

Derived data.

- `runner_speed_index_id` UNIQUE
- `reference_race_result_runner_id`
- `resolver_version`
- `resolved_at`

referenceを解決できない場合は行を作らない。resolver変更時は全件再構築できる。

### `race_results`

reference判定に必要なKD3成績のclassificationを、成績sourceのlineageと同じrowに保持する。

- `source_category_code`
- `discipline_code`

`kol_sei1.kd3` から保存し、canonical identityである `races` にはKD3固有コードを持ち込まない。

### Removed `horse_race_histories`

`kol_uma.kd3` の履歴blockは配布時点の再掲スナップショットで、canonical result と重複するため保存しない。Parserも該当blockをdecodeしない。

## Rebuild / backfill

この変更では `php artisan migrate` 後、既存 `source_files` を保持したままKD3をoldest-to-newestで全量再インポートする。same-source replayでも新設classification / cancellation type / speed index正規化をrefreshするため、raw sourceの再ダウンロードは不要である。reference coverageは現行parser/importer/specの成功runだけを有効とする。`migrate:fresh` は `source_files` も消すため、raw metadataを再構築する意図がない限り必須ではない。

運用上は、中央の `race_calendars` を対象期間までbackfillした後に次の順序で実行する。

```bash
php artisan migrate
php artisan kd3:import --from=<first-kd3-date> --to=<latest-downloaded-date>
```

batch import中に1 sourceでも失敗した場合は最終reconciliationを実行しない。失敗sourceを修復して再実行し、現行versionのIB coverageが連続した範囲だけreferenceを確定させる。

2008-05-18のウオッカHBがcoverageに入ったら、2008-03-29 Dubai Duty Free がslotを消費しないことを実データ回帰テストへ追加する。
