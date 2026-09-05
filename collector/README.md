# JV-Link Collector

.NET 10。開催scheduleに加え、単複 historical/realtime、速報event、SQLite Outboxを扱う。Coreのclient interfaceはschedule / odds history / realtimeで分離し、COM外のHTTP/SQLiteと混在させない。

## Windowsでの実行

JV-Linkの契約・インストール・利用設定済みであること。確認済み環境は32bit COMのため、**x86の.NET 10 SDK/runtime**が必要。管理者権限なしのユーザー領域へ公式installerで導入できる。

PowerShellでリポジトリrootから実行する（WSLなら `Set-Location '\\wsl.localhost\Ubuntu-26.04\home\dkuro\keiba'`）。

```powershell
Invoke-WebRequest https://dot.net/v1/dotnet-install.ps1 -OutFile "$env:TEMP\dotnet-install.ps1"
& "$env:TEMP\dotnet-install.ps1" -Channel 10.0 -Architecture x86 -InstallDir "$env:LOCALAPPDATA\keiba-poc\dotnet"
$dotnet = "$env:LOCALAPPDATA\keiba-poc\dotnet\dotnet.exe"
& $dotnet restore collector/Keiba.Collector.Cli/Keiba.Collector.Cli.csproj --locked-mode
& $dotnet build collector/Keiba.Collector.Cli/Keiba.Collector.Cli.csproj -c Release --no-restore
```

先にWSLでREADMEのCompose起動・dev Migrationを済ませる。ローカル `.env` の `JVLINK_INGEST_TOKEN` に十分長いランダムtokenを設定する（例: `openssl rand -hex 32` の結果）。config cacheは使用しない。次にPowerShellで同じtokenを環境変数へ読み、実行する。以下はtokenを標準出力へ出さない。

```powershell
$env:KEIBA_API_URL = 'http://localhost:8080'
$env:KEIBA_INGEST_TOKEN = ((Get-Content .env | Where-Object { $_ -match '^JVLINK_INGEST_TOKEN=' }) -split '=', 2)[1]
try {
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll schedule 20260801000000
    if ($LASTEXITCODE -ne 0) { throw "Collector failed: $LASTEXITCODE" }
} finally {
    Remove-Item Env:KEIBA_INGEST_TOKEN
}
```

引数はJVOpenのfromtime（yyyyMMddHHmmss）。必要な配信が含まれる期間を指定する。取得0件は正常終了するが、実データ検証の成功とは扱わない。標準出力はAPI集計値、標準エラーはJV-Linkの結果コード・件数。原本・payload・tokenは出力しない。Ctrl+Cで停止要求を送り、COMはfinallyでcloseする。

401はtoken、409はmapping、422はpayloadを確認。503はAPI設定/可用性、COM登録エラーはx86 SDKとJV-Link登録を確認する。

## Live / historical / Outbox

Outboxは必ずGit管理外の絶対pathを指定する。normalized API DTOだけを保存し、JV-Link raw recordは保存しない。

```powershell
$env:KEIBA_OUTBOX_PATH = Join-Path $env:LOCALAPPDATA 'keiba\outbox.sqlite'
$env:KEIBA_API_URL = 'http://localhost:8080'
$env:KEIBA_INGEST_TOKEN = ((Get-Content .env | Where-Object { $_ -match '^JVLINK_INGEST_TOKEN=' }) -split '=', 2)[1]
try {
    # race key = YYYYMMDD + 競馬場code + race no
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll live collect 202609050101
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll outbox status
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll outbox flush
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll odds fetch 202609050101
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll odds backfill --from 2008-01-01 --to 2008-01-02
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll odds coverage
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll odds coverage sync
} finally {
    Remove-Item Env:KEIBA_INGEST_TOKEN
}
```

`live collect` と `odds fetch/backfill` はeventをHTTP送信せず、transaction commit済みpendingだけを増やす。`outbox flush` が最大500件を送り、network/timeout/429/5xxとcanonical未登録をbackoff後に再送し、identity conflictなどterminal 4xxを削除せずdeadにする。HTTP成功後sent更新前にcrashした場合は同じ `source_event_id` が再送され、Laravelがunchangedにする。

range plannerは2008-01-01より前を拒否する。race-key単位coverageは各照会直後に同じSQLiteへ冪等保存し、確定済みraceを再開時にスキップする。`odds coverage` は日付範囲を含む集計、`odds coverage sync` は取得run/coverageのPostgreSQL同期である。公式保証外のno-dataは推測補完しない。全期間は長時間運用になるため、まず `odds fetch` と短いrangeで契約を確認する。

## 合成データのテスト

Windows/Linuxの.NET 10 SDKで実行でき、JV-LinkやAPI起動は不要。

```powershell
dotnet restore collector/Keiba.Collector.Tests/Keiba.Collector.Tests.csproj --locked-mode
dotnet test collector/Keiba.Collector.Tests/Keiba.Collector.Tests.csproj -c Release --no-restore
```

Windows CLIのbuildにもvendor DLLは不要。実行には登録済みCOMが必要。`packages.lock.json` をcommitし、通常restoreはlocked modeで行う。

[schedule API・仕様](../docs/architecture/jvlink-schedules.md)、[live/outbox仕様](../docs/architecture/jvlink-live.md)、[Issue #8実機検証](../docs/testing/issue-8-verification.md)を参照。
