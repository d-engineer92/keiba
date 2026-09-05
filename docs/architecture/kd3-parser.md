# KD3 Parser（Issue #6）

`source_files` の immutable LZH を入力とし、domain table への保存とは独立して検証済み typed package を返す。`php artisan kd3:parse --source-file=<id>` は同じAPIを呼び、結果を `kd3_parse_runs` に監査記録する。

```text
source_files / private Filesystem
  → size・SHA-256検証
  → LZH entryの事前列挙・flat filename検証
  → 一時directoryへ展開
  → canonical layout / fixed-width CRLF reader
  → raw byte slice / CP932 decode / strict type conversion
  → artifact date・主要key・package間整合性検証
  → Kd3ParsedPackage / Kd3ParsedRecord
```

## Layout と version

`Kd3LayoutRegistry` が対象12ファイルのCRLF込みrecord length、主要fieldのbyte offset・length・type・nullable・trim rule、spec versionを一元管理する。主要fieldは確認済み仕様のrace/date/horse/countだけを定義し、未確認位置を推測で追加しない。`KD3_SPEC_VERSION` は物理仕様、`KD3_PARSER_VERSION` は実装挙動を表し、parse runごとに両方を保存する。

fieldは文字列全体を先に変換せず、raw recordからbyte単位で切り出してCP932を検証・UTF-8化する。codeは先頭ゼロを保持するstring、numericは数字のみ、dateは実在する `YYYYMMDD` のみを許可し、space-only nullable fieldはnullとする。

## Package contract

`hb` はden1/den2/uma、`ib` はsei1/sei2/umaを必須とする。ibのsei3は存在すればcanonical physical/key validationを行うが、欠落は正常とする。odds/commentは全race・全horseへの掲載を要求せず、存在recordのphysical/date/keyだけを検証する。commentのrecord dateは過去走を参照し得るためartifact dateとの一致は要求せず、日付自体の妥当性だけを検査する。hb/ibではduplicate race・runner・horse、header頭数とrunner grouped count、runner horseの同一package uma参照、runner raceのheader参照を検査する。

返却DTOはsource file id、原本filename、artifact type、internal filename、1-based record number、typed fieldsを保持する。これによりIssue #7は一時展開pathやraw有償本文に依存せず同じParser APIからdomain mappingできる。

## LZH と diagnostics

runtime decoderは `lhasa` の `/usr/bin/lha`。Symfony Processの引数配列で `lha l` を実行し、absolute path、separator、`..`、許可以外の文字を含むentryを展開前に拒否してから、専用一時directoryへ展開する。一時directoryは成功・失敗を問わず削除する。

エラーはcategory、source file/artifact/original filename、internal filename、record number、byte offset、fieldを保持し、auditには安全な位置情報だけを保存する。raw record、ローカル絶対path、資格情報はstdout・DB・ログへ保存しない。

CIでは自作fixed-width bytesと、著作物を含まない最小 `-lh0-` fixtureを使用する。fixtureはarchive作成非対応のlhasaに作成を依存せず、実runtime extractorの list → preflight → extract を検証する。private regression結果は [Issue #5検証記録](../testing/issue-5-verification.md) に記録する。
