using System.Globalization;
using System.Text.Json;
using Keiba.Collector.Core;
using Keiba.Collector.JvLink;

namespace Keiba.Collector.Cli;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        try
        {
            using var cancellation = new CancellationTokenSource();
            Console.CancelKeyPress += (_, eventArgs) => { eventArgs.Cancel = true; cancellation.Cancel(); };
            return Run(args, cancellation.Token);
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine(exception is JvLinkException or IngestApiException or EventIngestApiException
                or FormatException or TimeoutException or ArgumentException or InvalidDataException
                ? exception.Message : $"Collector failed: {exception.GetType().Name}.");
            return 1;
        }
    }

    private static int Run(string[] args, CancellationToken cancellationToken)
    {
        if (args.Length == 2 && args[0] == "schedule") return Schedule(args[1], cancellationToken);
        if (args.Length == 3 && args[0] == "live" && args[1] == "collect") return CollectLive(args[2], cancellationToken);
        if (args.Length == 2 && args[0] == "outbox" && args[1] == "flush") return Flush(cancellationToken);
        if (args.Length == 2 && args[0] == "outbox" && args[1] == "status") return OutboxStatus();
        if (args.Length == 2 && args[0] == "odds" && args[1] == "coverage") return CoverageStatus();
        if (args.Length == 3 && args[0] == "odds" && args[1] == "coverage" && args[2] == "sync") return SyncCoverage(cancellationToken);
        if (args.Length == 3 && args[0] == "odds" && args[1] == "fetch") return FetchOdds(args[2], cancellationToken);
        if (args.Length == 6 && args[0] == "odds" && args[1] == "backfill" && args[2] == "--from" && args[4] == "--to")
            return Backfill(args[3], args[5], cancellationToken);
        if (args.Length == 1) return Schedule(args[0], cancellationToken);
        Console.Error.WriteLine("Usage: schedule <yyyyMMddHHmmss> | live collect <YYYYMMDDJJRR> | outbox flush|status | odds fetch <YYYYMMDDJJRR> | odds coverage [sync] | odds backfill --from <yyyy-MM-dd> --to <yyyy-MM-dd>");
        return 2;
    }

    private static int Schedule(string value, CancellationToken cancellationToken)
    {
        if (!DateTime.TryParseExact(value, "yyyyMMddHHmmss", CultureInfo.InvariantCulture, DateTimeStyles.None, out var from))
            throw new FormatException("Schedule fromtime must be yyyyMMddHHmmss.");
        using var http = Http();
        var sync = new SyncSchedules(new WindowsJvLinkClient(Console.Error.WriteLine),
            new LaravelScheduleClient(http, Endpoint(), Token()), TimeProvider.System);
        var result = sync.RunAsync(from, cancellationToken).GetAwaiter().GetResult();
        Write(result);
        return 0;
    }

    private static int CollectLive(string raceKey, CancellationToken cancellationToken)
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        using var client = new WindowsJvLiveClient(Console.Error.WriteLine);
        var events = client.ReadEvents(raceKey, DateTimeOffset.UtcNow, cancellationToken);
        foreach (var item in events) outbox.Enqueue(item);
        Write(new { collected = events.Count, outbox = outbox.Summary() });
        return 0;
    }

    private static int Flush(CancellationToken cancellationToken)
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        using var http = Http();
        var result = new FlushOutbox(outbox, new LaravelEventClient(http, Endpoint(), Token()), TimeProvider.System)
            .RunAsync(cancellationToken).GetAwaiter().GetResult();
        Write(result);
        return result.Dead == 0 ? 0 : 1;
    }

    private static int OutboxStatus()
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        Write(outbox.Summary());
        return 0;
    }

    private static int CoverageStatus()
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        Write(outbox.CoverageSummary());
        return 0;
    }

    private static int Backfill(string fromValue, string toValue, CancellationToken cancellationToken)
    {
        if (!DateOnly.TryParseExact(fromValue, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var from)
            || !DateOnly.TryParseExact(toValue, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var to))
            throw new FormatException("Backfill dates must be yyyy-MM-dd.");
        var dates = HistoricalRangePlanner.Dates(from, to);
        using var outbox = new SqliteEventOutbox(OutboxPath());
        using var client = new WindowsJvLiveClient(Console.Error.WriteLine);
        var started = DateTimeOffset.UtcNow;
        var runId = outbox.BeginBackfill(from, to, started);
        var found = 0;
        var racesFound = 0;
        DateOnly? actualMin = null;
        DateOnly? actualMax = null;
        try
        {
            foreach (var date in dates)
            {
                foreach (var venue in Enumerable.Range(1, 10))
                foreach (var race in Enumerable.Range(1, 12))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    var key = $"{date:yyyyMMdd}{venue:00}{race:00}";
                    if (outbox.HasFinalCoverage(key)) continue;
                    IReadOnlyList<JvEvent> events;
                    try
                    {
                        events = client.ReadWinPlaceHistory(key, DateTimeOffset.UtcNow, cancellationToken);
                    }
                    catch
                    {
                        outbox.RecordCoverage(key, "error", [], DateTimeOffset.UtcNow);
                        throw;
                    }
                    var inserted = events.Count(item => outbox.Enqueue(item));
                    outbox.RecordCoverage(key, events.Count > 0 ? "available" : "no_data", events, DateTimeOffset.UtcNow);
                    if (events.Count == 0) continue;
                    found += inserted;
                    racesFound++;
                    actualMin = actualMin is null || date < actualMin ? date : actualMin;
                    actualMax = actualMax is null || date > actualMax ? date : actualMax;
                }
            }
            outbox.FinishBackfill(runId, "completed", dates.Count * 120, racesFound, found, actualMin, actualMax, DateTimeOffset.UtcNow);
        }
        catch
        {
            outbox.FinishBackfill(runId, "failed", dates.Count * 120, racesFound, found, actualMin, actualMax,
                DateTimeOffset.UtcNow, "collector_error");
            throw;
        }
        Write(new { source_run_id = runId, requested_from = from, requested_to = to, snapshots = found, outbox = outbox.Summary() });
        return 0;
    }

    private static int FetchOdds(string raceKey, CancellationToken cancellationToken)
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        using var client = new WindowsJvLiveClient(Console.Error.WriteLine);
        var events = client.ReadWinPlaceHistory(raceKey, DateTimeOffset.UtcNow, cancellationToken);
        foreach (var item in events) outbox.Enqueue(item);
        outbox.RecordCoverage(raceKey, events.Count > 0 ? "available" : "no_data", events, DateTimeOffset.UtcNow);
        Write(new { snapshots = events.Count, outbox = outbox.Summary() });
        return 0;
    }

    private static int SyncCoverage(CancellationToken cancellationToken)
    {
        using var outbox = new SqliteEventOutbox(OutboxPath());
        var run = outbox.LatestUnsyncedRun();
        if (run is null)
        {
            Write(new { synced_runs = 0, synced_coverages = 0 });
            return 0;
        }
        var coverages = outbox.UnsyncedCoverages(run.RequestedFrom, run.RequestedTo, 100_000);
        using var http = Http();
        var client = new LaravelBackfillClient(http, Endpoint(), Token());
        if (coverages.Count == 0)
            client.SyncAsync(run, [], cancellationToken).GetAwaiter().GetResult();
        else
            foreach (var batch in coverages.Chunk(1000))
                client.SyncAsync(run, batch, cancellationToken).GetAwaiter().GetResult();
        outbox.MarkBackfillSynced(run.SourceRunId, coverages, DateTimeOffset.UtcNow);
        Write(new { synced_runs = 1, synced_coverages = coverages.Count, source_run_id = run.SourceRunId });
        return 0;
    }

    private static HttpClient Http() => new(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromSeconds(60) };
    private static Uri Endpoint() => new(Environment.GetEnvironmentVariable("KEIBA_API_URL") ?? "http://localhost:8080");
    private static string Token() => Environment.GetEnvironmentVariable("KEIBA_INGEST_TOKEN") ?? "";
    private static string OutboxPath() => Environment.GetEnvironmentVariable("KEIBA_OUTBOX_PATH")
        ?? throw new ArgumentException("KEIBA_OUTBOX_PATH is required for live and historical commands.");
    private static void Write<T>(T value) => Console.WriteLine(JsonSerializer.Serialize(value, LaravelScheduleClient.JsonOptions));
}
