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

public interface IJvLinkClient
{
    IReadOnlyList<Schedule> ReadSchedules(DateTime from, CancellationToken cancellationToken);
}

public interface IScheduleSink
{
    Task<IngestResult> SendAsync(ScheduleBatch batch, CancellationToken cancellationToken);
}

public sealed class SyncSchedules(IJvLinkClient source, IScheduleSink sink, TimeProvider clock)
{
    public async Task<IngestResult> RunAsync(DateTime from, CancellationToken cancellationToken = default)
    {
        var schedules = source.ReadSchedules(from, cancellationToken);
        var capturedAt = clock.GetUtcNow();
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
