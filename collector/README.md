# JV-Link schedule Collector

.NET 10。Core（DTO / IJvLinkClient / use case / HTTP）、JvLink（YS parser / Windows COM adapter）、Cli（手動実行）、Tests（合成レコードとFake）の4project。

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
    & $dotnet collector/Keiba.Collector.Cli/bin/Release/net10.0-windows/win-x86/Keiba.Collector.Cli.dll 20260801000000
    if ($LASTEXITCODE -ne 0) { throw "Collector failed: $LASTEXITCODE" }
} finally {
    Remove-Item Env:KEIBA_INGEST_TOKEN
}
```

引数はJVOpenのfromtime（yyyyMMddHHmmss）。必要な配信が含まれる期間を指定する。取得0件は正常終了するが、実データ検証の成功とは扱わない。標準出力はAPI集計値、標準エラーはJV-Linkの結果コード・件数。原本・payload・tokenは出力しない。Ctrl+Cで停止要求を送り、COMはfinallyでcloseする。

401はtoken、409はvenue mapping、422はpayloadを確認。503はAPI設定/可用性、COM登録エラーはx86 SDKとJV-Link登録を確認する。5xx/429を再試行可能な分類として扱うが、PoCに自動retryはない。

## 合成データのテスト

Windows/Linuxの.NET 10 SDKで実行でき、JV-LinkやAPI起動は不要。

```powershell
dotnet restore collector/Keiba.Collector.Tests/Keiba.Collector.Tests.csproj --locked-mode
dotnet test collector/Keiba.Collector.Tests/Keiba.Collector.Tests.csproj -c Release --no-restore
```

Windows CLIのbuildにもvendor DLLは不要。実行には登録済みCOMが必要。`packages.lock.json` をcommitし、通常restoreはlocked modeで行う。

[API・仕様根拠・制限](../docs/architecture/jvlink-schedules.md)、[実機検証記録](../docs/testing/issue-4-verification.md)を参照。
