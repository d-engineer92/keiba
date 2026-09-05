using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace Keiba.Collector.Core;

public sealed record Schedule(
    string VenueCode, string? VenueName, DateOnly RaceDate,
    int? MeetingNo, int? MeetingDay, string Status, DateTimeOffset? SourceUpdatedAt);

public sealed record ScheduleBatch(DateTimeOffset CapturedAt, IReadOnlyList<Schedule> Schedules);
public sealed record IngestResult(int Received, int Inserted, int Updated, int Unchanged);
public sealed record ScheduleSetupRange(string FromTime, DateOnly FilterFrom, DateOnly FilterTo);
public sealed record WeeklySchedulePlan(DateTime FromTime, DateOnly FilterFrom, DateOnly FilterTo);

public interface IJvLinkClient
{
    IReadOnlyList<Schedule> ReadSchedules(DateTime from, CancellationToken cancellationToken);
}

public interface IJvLinkSetupClient
{
    IReadOnlyList<Schedule> ReadSetupSchedules(string fromTime, CancellationToken cancellationToken);
}

public interface IScheduleSink
{
    Task<IngestResult> SendAsync(ScheduleBatch batch, CancellationToken cancellationToken);
}

public static class ScheduleSetupRangePlanner
{
    private static readonly DateOnly EarliestSupportedDate = new(2000, 1, 1);

    public static IReadOnlyList<ScheduleSetupRange> Ranges(DateOnly from, DateOnly to)
    {
        if (from > to) throw new ArgumentException("Schedule setup from date must not be later than to date.");
        if (from < EarliestSupportedDate) throw new ArgumentException("YSCH setup is supported from 2000-01-01 onward.");

        var ranges = new List<ScheduleSetupRange>();
        for (var year = from.Year; year <= to.Year; year++)
        {
            var filterFrom = year == from.Year ? from : new DateOnly(year, 1, 1);
            var filterTo = year == to.Year ? to : new DateOnly(year, 12, 31);
            var setupStart = new DateOnly(year, 1, 1);
            var start = $"{setupStart:yyyyMMdd}000000";

            // YSCH setup is year-based. Always request from January 1 even when the caller
            // needs only part of the first year, then filter by RaceDate before sending.
            // Setup files can use 99 in the file-identification date, so historical yearly
            // chunks use an inclusive sentinel to avoid dropping December aggregates. The
            // final setup call intentionally has no ToTime, as recommended by the SDK guide.
            var fromTime = year == to.Year
                ? start
                : $"{start}-{year:0000}1299999999";
            ranges.Add(new ScheduleSetupRange(fromTime, filterFrom, filterTo));
        }

        return ranges;
    }
}

public static class WeeklySchedulePlanner
{
    public static WeeklySchedulePlan Plan(DateOnly today)
    {
        var daysUntilNextMonday = ((int)DayOfWeek.Monday - (int)today.DayOfWeek + 7) % 7;
        if (daysUntilNextMonday == 0) daysUntilNextMonday = 7;

        var filterFrom = today.AddDays(daysUntilNextMonday);
        var filterTo = filterFrom.AddDays(6);

        // YSCH normal data is a distribution-time query, not a race-date query. Use a
        // one-year lookback and filter the returned schedules to next week so a schedule
        // first distributed months earlier is still found without using setup data weekly.
        var queryFrom = today.AddYears(-1);
        var fromTime = new DateTime(queryFrom.Year, queryFrom.Month, queryFrom.Day, 0, 0, 0, DateTimeKind.Unspecified);

        return new WeeklySchedulePlan(fromTime, filterFrom, filterTo);
    }
}

public sealed class SyncSchedules(IJvLinkClient source, IScheduleSink sink, TimeProvider clock)
{
    public async Task<IngestResult> RunAsync(DateTime from, CancellationToken cancellationToken = default)
    {
        var schedules = source.ReadSchedules(from, cancellationToken);
        return await SendAsync(schedules, clock.GetUtcNow(), cancellationToken);
    }

    public async Task<IngestResult> RunAsync(
        DateTime from,
        DateOnly filterFrom,
        DateOnly filterTo,
        CancellationToken cancellationToken = default)
    {
        if (filterFrom > filterTo) throw new ArgumentException("Schedule filter from date must not be later than to date.");
        var schedules = source.ReadSchedules(from, cancellationToken)
            .Where(x => x.RaceDate >= filterFrom && x.RaceDate <= filterTo)
            .ToArray();
        return await SendAsync(schedules, clock.GetUtcNow(), cancellationToken);
    }

    private async Task<IngestResult> SendAsync(
        IReadOnlyList<Schedule> schedules,
        DateTimeOffset capturedAt,
        CancellationToken cancellationToken)
    {
        var total = new IngestResult(0, 0, 0, 0);
        // Read and HTTP transport remain separate so a durable outbox can be added later.
        foreach (var chunk in schedules.Chunk(1000))
        {
            var result = await sink.SendAsync(new ScheduleBatch(capturedAt, chunk), cancellationToken);
            total = new(total.Received + result.Received, total.Inserted + result.Inserted,
                total.Updated + result.Updated, total.Unchanged + result.Unchanged);
        }
        return total;
    }
}

public sealed class SyncSetupSchedules(IJvLinkSetupClient source, IScheduleSink sink, TimeProvider clock)
{
    public async Task<IngestResult> RunAsync(DateOnly from, DateOnly to, CancellationToken cancellationToken = default)
    {
        var capturedAt = clock.GetUtcNow();
        var total = new IngestResult(0, 0, 0, 0);
        foreach (var range in ScheduleSetupRangePlanner.Ranges(from, to))
        {
            cancellationToken.ThrowIfCancellationRequested();
            var schedules = source.ReadSetupSchedules(range.FromTime, cancellationToken)
                .Where(x => x.RaceDate >= range.FilterFrom && x.RaceDate <= range.FilterTo)
                .ToArray();
            foreach (var chunk in schedules.Chunk(1000))
            {
                var result = await sink.SendAsync(new ScheduleBatch(capturedAt, chunk), cancellationToken);
                total = new(total.Received + result.Received, total.Inserted + result.Inserted,
                    total.Updated + result.Updated, total.Unchanged + result.Unchanged);
            }
        }
        return total;
    }
}

public sealed class IngestApiException(HttpStatusCode statusCode)
    : Exception($"Schedule API returned HTTP {(int)statusCode}.")
{
    public HttpStatusCode StatusCode { get; } = statusCode;
    public bool IsTransient => (int)StatusCode >= 500 || StatusCode == HttpStatusCode.TooManyRequests;
}

public sealed class LaravelScheduleClient : IScheduleSink
{
    public static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower
    };
    private readonly HttpClient http;
    private readonly string token;
    private readonly Uri endpoint;

    public LaravelScheduleClient(HttpClient http, Uri baseUri, string token)
    {
        if (!baseUri.IsAbsoluteUri || baseUri.Scheme is not ("http" or "https"))
            throw new ArgumentException("An absolute HTTP(S) base URL is required.", nameof(baseUri));
        if (baseUri.Scheme == "http" && !baseUri.IsLoopback)
            throw new ArgumentException("Use HTTPS except for a loopback development endpoint.", nameof(baseUri));
        if (string.IsNullOrWhiteSpace(token)) throw new ArgumentException("An internal token is required.", nameof(token));
        this.http = http;
        this.token = token;
        endpoint = new Uri(baseUri, "/api/internal/v1/jvlink/schedules");
    }

    public async Task<IngestResult> SendAsync(ScheduleBatch batch, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Content = JsonContent.Create(batch, options: JsonOptions);
        using var response = await http.SendAsync(request, cancellationToken);
        // Never log the request, token, or a server response that could contain a payload.
        if (!response.IsSuccessStatusCode) throw new IngestApiException(response.StatusCode);
        var result = await response.Content.ReadFromJsonAsync<IngestResult>(JsonOptions, cancellationToken)
            ?? throw new InvalidDataException("Schedule API returned an empty response.");
        if (result.Received != batch.Schedules.Count || result.Inserted < 0 || result.Updated < 0 || result.Unchanged < 0
            || (long)result.Inserted + result.Updated + result.Unchanged != result.Received)
            throw new InvalidDataException("Schedule API returned inconsistent counts.");
        return result;
    }
}
