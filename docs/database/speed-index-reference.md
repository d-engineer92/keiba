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

resolver v1.0.0 は過去の `race_result_runners` から以下をすべて満たすものだけを候補にする。

1. 同一 horse
2. target race より前の日付
3. `races.source_category_code = '0'`（中央）
4. `races.discipline_code = '0'`（平地）
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

### `races`

reference判定に必要な canonical classification を保持する。

- `source_category_code`
- `discipline_code`

`kol_den1.kd3` / `kol_sei1.kd3` から同じraceへマージし、既存値と矛盾した場合は上書きせずintegrity errorにする。

### Removed `horse_race_histories`

`kol_uma.kd3` の履歴blockは配布時点の再掲スナップショットで、canonical result と重複するため保存しない。Parserも該当blockをdecodeしない。

## Rebuild / backfill

この変更は canonical history の意味を変えるため、開発中DBでは migration 後の部分補正より `migrate:fresh` + KD3 oldest-to-newest reimport を推奨する。

2008-05-18のウオッカHBがcoverageに入ったら、2008-03-29 Dubai Duty Free がslotを消費しないことを実データ回帰テストへ追加する。
