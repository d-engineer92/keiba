# KD3 Parser（Issue #6 / Issue #30再設計中）

`source_files` の immutable LZH を入力とし、domain table への保存とは独立して検証済み typed package を返す。`php artisan kd3:parse --source-file=<id>` は同じAPIを呼び、結果を `kd3_parse_runs` に監査記録する。

```text
source_files / private Filesystem
  → size・SHA-256検証
  → LZH entryの事前列挙・flat filename検証
  → 一時directoryへ展開
  → physical record / layout validation
  → raw byte slice / CP932 decode / strict type conversion
  → artifact date・主要key・package間整合性検証
  → Kd3ParsedPackage / Kd3ParsedRecord
```

> [!IMPORTANT]
> 2026-09-06の2007年実データ検証で、ファイル全体の `filesize % record_length == 0` を前提にlayoutを選択する方式が実データに適合しないことが確認された。PR #31上の現実装は再設計途中であり、19年分の全量importを再開しない。確定した検証結果は [Issue #30 検証記録](../testing/issue-30-verification.md) を参照。

## Layout と version

`Kd3LayoutRegistry` が対象ファイルのCRLF込みrecord length、主要fieldのbyte offset・length・type・nullable・trim rule、spec versionを一元管理する。主要fieldは公式仕様または実データで確認した位置だけを定義し、未確認位置を推測で確定扱いしない。`KD3_SPEC_VERSION` は参照物理仕様、`KD3_PARSER_VERSION` は実装挙動を表し、parse runごとに両方を保存する。

fieldは文字列全体を先に変換せず、raw recordからbyte単位で切り出してCP932を検証・UTF-8化する。codeは先頭ゼロを保持するstring、numericは数字のみ、dateは実在する `YYYYMMDD` のみを許可し、space-only nullable fieldはnullとする。

### 公式改版とlegacy layout

KD3公式仕様の改版履歴では2007-10-10に少なくとも以下の物理変更が明記されている。

- `kol_ods2`: 予備フィールド 92→1677、馬単 7→5桁。
- `kol_kod2`: 複数の予備フィールド変更。
- `kol_kod3`: 予備フィールド変更と後続address修正。

改版差分から初期record lengthの候補を復元できるが、**2007-10-10より前の実sourceで確認するまではcandidateに留める**。archive日だけでlayoutを決め打ちしない。

## 実データで確定したphysical anomaly

`source_file=12821`（JB 2007-10-21）の `kol_ods2.kd3` を実展開したところ、36 records中35 recordsは9043 bytes、record 11だけ9044 bytesだった。

record 11は本文9042 bytes + 正常なCRLF 2 bytes。現行offsetで馬単306 slot、3連複816 slotの全1122 slotが成立し、offsetを+1すると大きく崩れる。期待payload 9041 bytesの後ろにspaceが1 byte追加された形だった。

```text
[9041-byte normal payload][extra space][CRLF]
```

これにより以下を確定した。

- 12821の `kol_ods2` は9043-byte世代であり、8070-byte legacy candidateではない。
- 9044 bytesを別layoutとして登録しない。
- file全体のsize divisibilityだけをlayout fingerprintに使わない。
- known anomalyを許容する場合も、expected payload / field offsetsが成立することをrecord単位で確認する。

全sourceで同型のtrailing spaceがどの程度存在するかは未監査である。9044 bytesを一般的に許容してはならない。

## Record boundary とphysical validation

再設計後は次の責務分離を目標とする。

```text
LZH internal file
  → CRLFでrecord boundaryを検出
  → recordごとの実byte lengthを確認
  → known layout candidateとfield validityを照合
  → 明示的に確認済みの物理異常だけ正規化
  → typed decode
```

現在の `Kd3FixedWidthReader` のように、file sizeがrecord lengthで割り切れることを先に要求してfixed-size `fread`する方式は見直す。

正規化はfail-openにしない。例えば「9044なら末尾1 byteを削除」ではなく、対象file/layout、extra byte位置・値、主要market offset、期待payload lengthを満たす既知ケースに限定する。

## Package contract

`hb` はden1/den2/uma、`ib` はsei1/sei2/umaを必須とする。ibのsei3は存在すればcanonical physical/key validationを行うが、欠落は正常とする。odds/commentは全race・全horseへの掲載を要求せず、存在recordのphysical/date/keyだけを検証する。commentのrecord dateは過去走を参照し得るためartifact dateとの一致は要求せず、日付自体の妥当性だけを検査する。hb/ibではduplicate race・runner・horse、header頭数とrunner grouped count、runner horseの同一package uma参照、runner raceのheader参照を検査する。

返却DTOはsource file id、原本filename、artifact type、internal filename、1-based record number、typed fieldsを保持する。これによりdomain importerは一時展開pathやraw有償本文に依存せず同じParser APIからdomain mappingできる。

## Record date policy

予想オッズは前日発売レースを含み得ることが公式仕様と2007年実sourceで確認された。

JBの `kol_ods.kd3` / `kol_ods2.kd3` はartifact日と同日、または正確に翌日のrace dateだけを許可する。任意の未来日を許可しない。

parser 1.2.0で、旧 `field_validation` だった以下が正常parseすることを確認済み。

| source_file | artifact date | records |
| ---: | --- | ---: |
| 12787 | 2007-10-13 | 50 |
| 12827 | 2007-10-20 | 74 |
| 12811 | 2007-10-27 | 74 |

## Zero-record publication file

JB等のoptional publication fileは0 recordsになり得る。0-byte fileでは複数layout profileを物理サイズから区別できないため、layoutを推測して選択せず、records=[]として扱う。空ファイルについて架空の `layout_version` を確定しない。

## LZH と diagnostics

runtime decoderは `lhasa` の `/usr/bin/lha`。Symfony Processの引数配列で `lha l` を実行し、absolute path、separator、`..`、許可以外の文字を含むentryを展開前に拒否してから、専用一時directoryへ展開する。一時directoryは成功・失敗を問わず削除する。

source archiveはtemp copy・size検証・SHA-256計算を1 stream passで行う。旧実装のSHA読込後に `Storage::get()` で再度archive全体を読む経路は廃止した。

エラーはcategory、source file/artifact/original filename、internal filename、record number、byte offset、fieldを保持し、auditには安全な位置情報だけを保存する。raw record、ローカル絶対path、資格情報はstdout・DB・ログへ保存しない。

## 検証方針

CIでは著作物を含まないsynthetic fixed-width bytesと最小 `-lh0-` fixtureを使用する。実KD3 rawはGitへ入れずprivate regressionで検証する。

今後はGoogle Drive等へ退避した全raw sourceを対象に、少なくとも以下を横断監査する。

- internal file構成・0-record file。
- record length分布。
- 2007-10改版前後のlayout実在確認。
- 9044-byte等のphysical anomaly頻度。
- 2019/2020改版前後のoffset整合性。

検証結果は [Issue #30 検証記録](../testing/issue-30-verification.md) に追記する。
