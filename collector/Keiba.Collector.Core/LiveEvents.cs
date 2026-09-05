using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Data.Sqlite;

namespace Keiba.Collector.Core;

public sealed record JvEvent(
    string SourceEventId,
    string EventType,
    string? SourceDataSpec,
    string? SourceRecordType,
    DateTimeOffset? SourcePublishedAt,
    DateTimeOffset? EffectiveAt,
    DateTimeOffset CapturedAt,
    string PayloadSha256,
    IReadOnlyDictionary<string, object?> Payload);

public static class JvEventFactory
{
    public static JvEvent Create(string eventType, string dataSpec, string recordType, string stableSourceKey,
        DateTimeOffset? publishedAt, DateTimeOffset capturedAt, IReadOnlyDictionary<string, object?> payload)
    {
        var sourceKeyHash = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(stableSourceKey)));
        var payloadBytes = JsonSerializer.SerializeToUtf8Bytes(payload, LaravelScheduleClient.JsonOptions);
        return new JvEvent($"jvlink:{dataSpec}:{recordType}:{sourceKeyHash}", eventType, dataSpec, recordType,
            publishedAt, null, capturedAt, Convert.ToHexStringLower(SHA256.HashData(payloadBytes)), payload);
    }
}

public interface IJvRealtimeClient
{
    IReadOnlyList<JvEvent> ReadEvents(string raceKey, DateTimeOffset capturedAt, CancellationToken cancellationToken);
}

public interface IJvOddsHistoryClient
{
    IReadOnlyList<JvEvent> ReadWinPlaceHistory(string raceKey, DateTimeOffset capturedAt, CancellationToken cancellationToken);
}

public sealed record PendingOutboxEvent(long Id, JvEvent Event, int AttemptCount);
public sealed record OutboxSummary(int Pending, int Sent, int Dead);
public sealed record BackfillCoverageSummary(int Available, int NoData, int Error, string? FirstDate, string? LastDate);

public sealed class SqliteEventOutbox : IDisposable
{
    private readonly SqliteConnection connection;

    public SqliteEventOutbox(string path)
    {
        if (string.IsNullOrWhiteSpace(path)) throw new ArgumentException("An outbox database path is required.", nameof(path));
        var builder = new SqliteConnectionStringBuilder { DataSource = Path.GetFullPath(path), Mode = SqliteOpenMode.ReadWriteCreate, Pooling = false };
        connection = new SqliteConnection(builder.ConnectionString);
        connection.Open();
        using var pragma = connection.CreateCommand();
        pragma.CommandText = "PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;";
        pragma.ExecuteNonQuery();
        Migrate();
    }

    public void Enqueue(JvEvent item)
    {
        var json = JsonSerializer.Serialize(item, LaravelScheduleClient.JsonOptions);
        using var transaction = connection.BeginTransaction();
        using var existing = connection.CreateCommand();
        existing.Transaction = transaction;
        existing.CommandText = "SELECT payload_sha256 FROM outbox_events WHERE source_event_id = $source_event_id";
        existing.Parameters.AddWithValue("$source_event_id", item.SourceEventId);
        var previousHash = existing.ExecuteScalar() as string;
        if (previousHash is not null && !string.Equals(previousHash, item.PayloadSha256, StringComparison.Ordinal))
            throw new InvalidDataException("An outbox source event ID was reused with a different payload hash.");
        if (previousHash is null)
        {
            using var insert = connection.CreateCommand();
            insert.Transaction = transaction;
            insert.CommandText = """
                INSERT INTO outbox_events
                  (source_event_id,event_type,payload_json,payload_sha256,captured_at,status,attempt_count,created_at)
                VALUES ($source_event_id,$event_type,$payload_json,$payload_sha256,$captured_at,'pending',0,$created_at)
                """;
            insert.Parameters.AddWithValue("$source_event_id", item.SourceEventId);
            insert.Parameters.AddWithValue("$event_type", item.EventType);
            insert.Parameters.AddWithValue("$payload_json", json);
            insert.Parameters.AddWithValue("$payload_sha256", item.PayloadSha256);
            insert.Parameters.AddWithValue("$captured_at", item.CapturedAt.ToString("O"));
            insert.Parameters.AddWithValue("$created_at", DateTimeOffset.UtcNow.ToString("O"));
            insert.ExecuteNonQuery();
        }
        transaction.Commit();
    }

    public IReadOnlyList<PendingOutboxEvent> Pending(int limit, DateTimeOffset now)
    {
        if (limit is < 1 or > 500) throw new ArgumentOutOfRangeException(nameof(limit));
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT id,payload_json,attempt_count FROM outbox_events
            WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= $now)
            ORDER BY id LIMIT $limit
            """;
        command.Parameters.AddWithValue("$now", now.ToString("O"));
        command.Parameters.AddWithValue("$limit", limit);
        using var reader = command.ExecuteReader();
        var events = new List<PendingOutboxEvent>();
        while (reader.Read())
        {
            var item = JsonSerializer.Deserialize<JvEvent>(reader.GetString(1), LaravelScheduleClient.JsonOptions)
                ?? throw new InvalidDataException("The outbox contains an invalid event envelope.");
            events.Add(new PendingOutboxEvent(reader.GetInt64(0), item, reader.GetInt32(2)));
        }
        return events;
    }

    public void MarkSent(long id, DateTimeOffset now) => Update(id, "sent", now, null, null, null);

    public void MarkFailed(long id, int previousAttempts, bool retryable, string category, int? httpStatus, DateTimeOffset now)
    {
        DateTimeOffset? next = retryable ? now.Add(RetryDelay(previousAttempts + 1, id)) : null;
        Update(id, retryable ? "pending" : "dead", now, next, category, httpStatus);
    }

    public OutboxSummary Summary()
    {
        using var command = connection.CreateCommand();
        command.CommandText = "SELECT status,COUNT(*) FROM outbox_events GROUP BY status";
        using var reader = command.ExecuteReader();
        var values = new Dictionary<string, int>(StringComparer.Ordinal);
        while (reader.Read()) values[reader.GetString(0)] = reader.GetInt32(1);
        return new(values.GetValueOrDefault("pending"), values.GetValueOrDefault("sent"), values.GetValueOrDefault("dead"));
    }

    public void RecordCoverage(DateOnly date, string status, int snapshotCount, DateTimeOffset checkedAt)
    {
        if (status is not ("available" or "no_data" or "error")) throw new ArgumentException("The coverage status is invalid.", nameof(status));
        using var command = connection.CreateCommand();
        command.CommandText = """
            INSERT INTO backfill_coverages(coverage_date,status,snapshot_count,last_checked_at)
            VALUES($date,$status,$count,$checked_at)
            ON CONFLICT(coverage_date) DO UPDATE SET status=excluded.status,
              snapshot_count=excluded.snapshot_count,last_checked_at=excluded.last_checked_at
            """;
        command.Parameters.AddWithValue("$date", date.ToString("yyyy-MM-dd"));
        command.Parameters.AddWithValue("$status", status);
        command.Parameters.AddWithValue("$count", snapshotCount);
        command.Parameters.AddWithValue("$checked_at", checkedAt.ToString("O"));
        command.ExecuteNonQuery();
    }

    public BackfillCoverageSummary CoverageSummary()
    {
        using var command = connection.CreateCommand();
        command.CommandText = """
            SELECT COALESCE(SUM(status='available'),0),COALESCE(SUM(status='no_data'),0),
              COALESCE(SUM(status='error'),0),MIN(coverage_date),MAX(coverage_date) FROM backfill_coverages
            """;
        using var reader = command.ExecuteReader();
        if (!reader.Read()) return new(0, 0, 0, null, null);
        return new(reader.GetInt32(0), reader.GetInt32(1), reader.GetInt32(2),
            reader.IsDBNull(3) ? null : reader.GetString(3), reader.IsDBNull(4) ? null : reader.GetString(4));
    }

    private void Update(long id, string status, DateTimeOffset now, DateTimeOffset? next, string? category, int? httpStatus)
    {
        using var command = connection.CreateCommand();
        command.CommandText = """
            UPDATE outbox_events SET status=$status,attempt_count=attempt_count+1,last_attempt_at=$now,
              next_attempt_at=$next,sent_at=CASE WHEN $status='sent' THEN $now ELSE sent_at END,
              last_error_category=$category,last_http_status=$http_status WHERE id=$id
            """;
        command.Parameters.AddWithValue("$status", status);
        command.Parameters.AddWithValue("$now", now.ToString("O"));
        command.Parameters.AddWithValue("$next", next?.ToString("O") ?? (object)DBNull.Value);
        command.Parameters.AddWithValue("$category", category ?? (object)DBNull.Value);
        command.Parameters.AddWithValue("$http_status", httpStatus ?? (object)DBNull.Value);
        command.Parameters.AddWithValue("$id", id);
        command.ExecuteNonQuery();
    }

    private static TimeSpan RetryDelay(int attempts, long id)
    {
        var baseSeconds = Math.Min(3600, 5 * Math.Pow(2, Math.Min(attempts - 1, 10)));
        var jitter = (id * 1103515245L & 0x7fffffff) % 1000 / 1000d;
        return TimeSpan.FromSeconds(baseSeconds * (0.75 + jitter * 0.5));
    }

    private void Migrate()
    {
        using var command = connection.CreateCommand();
        command.CommandText = """
            CREATE TABLE IF NOT EXISTS schema_versions(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS outbox_events(
              id INTEGER PRIMARY KEY AUTOINCREMENT, source_event_id TEXT NOT NULL UNIQUE,
              event_type TEXT NOT NULL, payload_json TEXT NOT NULL, payload_sha256 TEXT NOT NULL,
              captured_at TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('pending','sent','dead')),
              attempt_count INTEGER NOT NULL DEFAULT 0, next_attempt_at TEXT NULL, last_attempt_at TEXT NULL,
              sent_at TEXT NULL, last_error_category TEXT NULL, last_http_status INTEGER NULL, created_at TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS outbox_pending_scan ON outbox_events(status,next_attempt_at,id);
            CREATE TABLE IF NOT EXISTS backfill_coverages(
              coverage_date TEXT PRIMARY KEY, status TEXT NOT NULL CHECK(status IN ('available','no_data','error')),
              snapshot_count INTEGER NOT NULL, last_checked_at TEXT NOT NULL);
            INSERT OR IGNORE INTO schema_versions(version,applied_at) VALUES(1,strftime('%Y-%m-%dT%H:%M:%fZ','now'));
            INSERT OR IGNORE INTO schema_versions(version,applied_at) VALUES(2,strftime('%Y-%m-%dT%H:%M:%fZ','now'));
            """;
        command.ExecuteNonQuery();
    }

    public void Dispose() => connection.Dispose();
}

public sealed record EventIngestResult(int Received, int Inserted, int Unchanged, int Conflicted);

public interface ILiveEventSink
{
    Task<EventIngestResult> SendAsync(IReadOnlyList<JvEvent> events, CancellationToken cancellationToken);
}

public sealed class LaravelEventClient(HttpClient http, Uri baseUri, string token) : ILiveEventSink
{
    private readonly Uri endpoint = Validate(baseUri, token);

    public async Task<EventIngestResult> SendAsync(IReadOnlyList<JvEvent> events, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Content = JsonContent.Create(new { Events = events }, options: LaravelScheduleClient.JsonOptions);
        using var response = await http.SendAsync(request, cancellationToken);
        if (!response.IsSuccessStatusCode) throw new EventIngestApiException(response.StatusCode);
        return await response.Content.ReadFromJsonAsync<EventIngestResult>(LaravelScheduleClient.JsonOptions, cancellationToken)
            ?? throw new InvalidDataException("Event API returned an empty response.");
    }

    private static Uri Validate(Uri baseUri, string token)
    {
        if (!baseUri.IsAbsoluteUri || baseUri.Scheme is not ("http" or "https"))
            throw new ArgumentException("An absolute HTTP(S) base URL is required.", nameof(baseUri));
        if (baseUri.Scheme == "http" && !baseUri.IsLoopback)
            throw new ArgumentException("Use HTTPS except for a loopback development endpoint.", nameof(baseUri));
        if (string.IsNullOrWhiteSpace(token)) throw new ArgumentException("An internal token is required.", nameof(token));
        return new Uri(baseUri, "/api/internal/v1/jvlink/events");
    }
}

public sealed class EventIngestApiException(HttpStatusCode statusCode)
    : Exception($"Event API returned HTTP {(int)statusCode}.")
{
    public HttpStatusCode StatusCode { get; } = statusCode;
    public bool IsTransient => StatusCode == HttpStatusCode.TooManyRequests || (int)StatusCode >= 500;
}

public sealed class FlushOutbox(SqliteEventOutbox outbox, ILiveEventSink sink, TimeProvider clock)
{
    public async Task<OutboxSummary> RunAsync(CancellationToken cancellationToken = default)
    {
        foreach (var pending in outbox.Pending(500, clock.GetUtcNow()))
        {
            try
            {
                var result = await sink.SendAsync([pending.Event], cancellationToken);
                if (result.Received != 1 || result.Inserted + result.Unchanged != 1 || result.Conflicted != 0)
                    throw new InvalidDataException("Event API returned inconsistent counts.");
                outbox.MarkSent(pending.Id, clock.GetUtcNow());
            }
            catch (EventIngestApiException exception)
            {
                outbox.MarkFailed(pending.Id, pending.AttemptCount, exception.IsTransient, "http", (int)exception.StatusCode, clock.GetUtcNow());
            }
            catch (HttpRequestException)
            {
                outbox.MarkFailed(pending.Id, pending.AttemptCount, true, "network", null, clock.GetUtcNow());
            }
            catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                outbox.MarkFailed(pending.Id, pending.AttemptCount, true, "timeout", null, clock.GetUtcNow());
            }
        }
        return outbox.Summary();
    }
}

public static class HistoricalRangePlanner
{
    public static readonly DateOnly AnalysisBaseline = new(2008, 1, 1);

    public static IReadOnlyList<DateOnly> Dates(DateOnly from, DateOnly to)
    {
        if (from < AnalysisBaseline) throw new ArgumentOutOfRangeException(nameof(from), "Historical odds begin at 2008-01-01.");
        if (to < from) throw new ArgumentException("The end date must not precede the start date.", nameof(to));
        var dates = new List<DateOnly>();
        for (var date = from; date <= to; date = date.AddDays(1)) dates.Add(date);
        return dates;
    }
}
