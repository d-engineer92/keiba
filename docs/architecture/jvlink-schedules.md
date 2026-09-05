# JV-Link 開催スケジュール取り込み（Issue #4）

Windows JV-Link → `IJvLinkClient` → `SyncSchedules` → `IScheduleSink` / HTTP → Laravel → PostgreSQL `race_calendars`。取得と送信を分離し、CoreはCOMを参照しない。実行方法は [Collector README](../../collector/README.md)。スキーマは既存Migrationを使用する。

## API契約

`POST /api/internal/v1/jvlink/schedules`、`Content-Type: application/json`、`Accept: application/json`、`Authorization: Bearer <JVLINK_INGEST_TOKEN>`。

Laravelの `.env` にローカル専用tokenを設定する。未設定は503、認証失敗は401。token・JV-Link利用キー・原本をリポジトリやログへ出力しない。HTTPはlocalhost開発用で、Collectorは非loopbackへの平文HTTPを拒否する。

以下は**合成例**であり実レコードではない。

```json
{
  "captured_at": "2026-09-05T00:00:00Z",
  "schedules": [{
    "venue_code": "01",
    "venue_name": "札幌競馬場",
    "race_date": "2026-09-05",
    "meeting_no": 3,
    "meeting_day": 5,
    "status": "scheduled",
    "source_updated_at": null
  }]
}
```

| 項目 | 契約 |
| --- | --- |
| captured_at | batch取得時刻。timezone必須ISO 8601文字列 |
| schedules | 1〜1000件のlist。未知のキーは拒否 |
| venue_code | 先頭ゼロを保持した2桁文字列。内部PKには使用しない |
| venue_name | キー必須、null可、最大255文字。未mapping時には名前が必要 |
| race_date | 厳密なYYYY-MM-DD |
| meeting_no / meeting_day | キー必須、nullまたは1〜32767のJSON整数 |
| status | scheduled / completed / cancelled / deleted |
| source_updated_at | キー必須、nullまたはtimezone必須ISO 8601。根拠のない時刻を補完しない |

不正payloadは422。競馬場mapping不整合は409。batch全体をtransactionで処理し、途中失敗は全件rollbackする。成功時は200で `{"received":1,"inserted":1,"updated":0,"unchanged":0}`。各入力行を一度集計し、inserted + updated + unchanged = received。同一batch内の重複も処理件数には含む。

## mappingと冪等性

`source_identifiers` の `(jvlink, venue, venue_code, コード値)` からvenueを解決する。既存mappingを優先し、名前がnullならそのまま利用する。名前の矛盾・参照先venue欠落は409とし、mappingを付け替えない。未mappingでは名前で既存venueを再利用、なければ作成し、同一transaction内でmappingを追加する。外部コードを `venues` のPKにしない。

`race_date + venue_id` が開催の自然キー。初回にfirst_seen_at / last_seen_atをcaptured_atで設定する。再送で行を増やさず、first_seen_atを保持、last_seen_atは最大値に更新する。同一payload（captured_at含む）の再送はunchanged。再取得はcaptured_atが進むため、業務項目が同じでもlast_seen_at更新をupdatedに含む。

両snapshotにsource_updated_atがある場合はその新旧を優先し、古い業務項目を適用しない。それ以外はcaptured_atで順序判定する。既存スキーマがTIMESTAMPTZ(0)なので比較前にUTC秒へ切り捨てる。同一秒は入力順で適用する。YSに更新時刻がないため、後日再取得した古い配信と新しい配信の厳密な順序判定は保証しない。

PoCではPostgreSQL transaction advisory lockでこのendpointのbatchを直列化し、存在しないmapping・自然キーの同時作成も保護する。将来の別writerも同じ協調が必要。大量並列取り込み時はlock粒度を再検討する。

## 公式仕様と確認済み環境

参照: [公式SDK提供ページ](https://jra-van.jp/dlb/sdv/sdk.html)、[JV-Linkインターフェース仕様書4.9.0.1](https://jra-van.jp/dlb/sdv/sdk/JV-Link4901.pdf)、[JV-Data仕様書4.9.0.1](https://jra-van.jp/dlb/sdv/sdk/JV-Data4901.pdf) の開催スケジュールYS・データ種別YSCH・コード表2001。

2026-09-05の実機では32bit ProgID `JVDTLab.JVLink`、CLSID `{2AB1774D-0C41-11D7-916F-0003479BEB3F}` が登録済み。DLLは `C:\Windows\SysWOW64\JVDTLAB\JVDTLab.dll`、ファイル版は `1, 1, 8, 0`。これをSDK製品版とは解釈しない。64bit COM登録はないため、CLIをWindows x86 / STAで実行する。COMはlate bindingで呼び、vendor DLLや生成Interopアセンブリを配布・commitしない。別バージョン・64bit版は未検証。

呼出順序は `JVInit("UNKNOWN")` → `JVOpen("YSCH", fromtime, 1, ...)` → ダウンロードがあれば `JVStatus` 待機 → `JVRead` loop → finally `JVClose`。fromtimeは開催日フィルタではなく配信データの取得開始時刻。option=1は通常取得。Open=-1は該当なし、Read=-1はファイル境界、Read=0は終了。他の負数は失敗として終了し、無限再試行しない。download最大3分、read全体最大5分、HTTP最大60秒。環境の契約・JV-Link設定は事前に完了している必要がある。

YSは382バイト固定長（CRLF含む）。使用位置は**1始まり**。

| 位置 / 長さ | 意味 |
| --- | --- |
| 1 / 2 | レコード種別YS |
| 3 / 1 | データ区分1・2=scheduled、3=completed、9=cancelled、0=deleted |
| 4 / 8 | データ作成年月日。timestampではない |
| 12 / 8 | 開催年月日 |
| 20 / 2 | 競馬場コード |
| 22 / 2 | 開催回 |
| 24 / 2 | 開催日目 |
| 381 / 2 | CRLF |

会場名はYS内にないため、中央01〜10は公式コード表2001の競馬場名を使用する。未知コードは名前nullとし、独自の名称を推測しない。開催回・日目のゼロ/空欄はnull。source_updated_atはnullのまま。取得内の同一自然キーは作成年月日が最新のもの、同日なら最後に読んだものを選ぶ。

JVReadはUnicode文字列を返すが、採用する先頭26文字はASCII領域。戻り値の原本バイト数382・CRLF・headerのASCII/日付/コードを検証する。不要な日本語競走名をCP932へ再エンコードしない（実環境で文字列の往復変換が失敗したため）。このparserは競走名など残りの領域を解析する用途には使用しない。

Issue #4では手動実行のsnapshot取り込みのみ。永続Outbox・自動retry・スケジューラはIssue #8以降。取得/送信境界を保ち、将来Outboxを挟める構成とする。API失敗時は終了コード1となり、必要に応じて同じfromtimeで再実行する。自動テストは合成データのみ使用する。
