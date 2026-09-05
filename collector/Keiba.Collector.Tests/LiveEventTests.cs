using System.Net;
using System.Text;
using Keiba.Collector.Core;
using Keiba.Collector.JvLink;

namespace Keiba.Collector.Tests;

public class LiveEventTests
{
    private static readonly DateTimeOffset Captured = new(2026, 9, 5, 1, 2, 3, TimeSpan.Zero);

    [Fact]
    public void ParsesHistoricalAndRealtimeWinPlaceWithoutHashingValuesAsIdentity()
    {
        var historical = JvLiveRecordParser.ParseOdds(Odds("09051030"), "historical_timeseries", "0B41", Captured);
        var realtime = JvLiveRecordParser.ParseOdds(Odds("09051030"), "realtime", "0B31", Captured);
        Assert.Equal("odds_snapshot", historical.EventType);
        Assert.Equal("historical_timeseries", historical.Payload["source_kind"]);
        Assert.Equal(2, Assert.IsAssignableFrom<IReadOnlyList<IReadOnlyDictionary<string, object?>>>(historical.Payload["items"]).Count);
        Assert.NotEqual(historical.SourceEventId, realtime.SourceEventId);

        var later = JvLiveRecordParser.ParseOdds(Odds("09051031"), "historical_timeseries", "0B41", Captured);
        Assert.NotEqual(historical.SourceEventId, later.SourceEventId);
        Assert.Equal(historical.PayloadSha256, JvLiveRecordParser.ParseOdds(Odds("09051030"), "historical_timeseries", "0B41", Captured).PayloadSha256);
    }

    [Fact]
    public void ParsesCancellationExclusionAndJockeyChange()
    {
        var cancelled = JvLiveRecordParser.ParseRunnerStatus(RunnerStatus('1'), Captured);
        var excluded = JvLiveRecordParser.ParseRunnerStatus(RunnerStatus('2'), Captured);
        var jockey = JvLiveRecordParser.ParseJockeyChange(JockeyChange(), Captured);
        Assert.Equal("cancelled", cancelled.Payload["status_type"]);
        Assert.Equal("excluded", excluded.Payload["status_type"]);
        Assert.Equal("54321", jockey.Payload["new_jockey_code"]);
        Assert.Equal("12345", jockey.Payload["old_jockey_code"]);
    }

    [Fact]
    public void ParsesBodyWeightAndWeatherHistory()
    {
        var weights = JvLiveRecordParser.ParseBodyWeights(BodyWeight(), Captured);
        var weight = Assert.Single(weights);
        Assert.Equal(478, weight.Payload["body_weight"]);
        Assert.Equal(-6, weight.Payload["body_weight_delta"]);
        var weather = JvLiveRecordParser.ParseWeather(Weather(), Captured);
        Assert.Equal("weather", weather.Payload["change_type"]);
        Assert.Equal("2", weather.Payload["weather"]);
        Assert.Equal("3", weather.Payload["turf_condition"]);
    }

    [Fact]
    public void RejectsMalformedRecordLengthAndFraming()
    {
        Assert.Throws<FormatException>(() => JvLiveRecordParser.ParseOdds(Odds("09051030")[..961], "realtime", "0B31", Captured));
        var record = Odds("09051030");
        record[^1] = (byte)' ';
        Assert.Throws<FormatException>(() => JvLiveRecordParser.ParseOdds(record, "realtime", "0B31", Captured));
    }

    [Fact]
    public void OutboxCommitsBeforeTransportAndSurvivesReopen()
    {
        var path = Path.Combine(Path.GetTempPath(), $"keiba-outbox-{Guid.NewGuid():N}.sqlite");
        try
        {
            var item = JvLiveRecordParser.ParseOdds(Odds("09051030"), "realtime", "0B31", Captured);
            using (var first = new SqliteEventOutbox(path)) first.Enqueue(item);
            using (var reopened = new SqliteEventOutbox(path))
            {
                Assert.Equal(1, reopened.Summary().Pending);
                Assert.Equal(item.SourceEventId, Assert.Single(reopened.Pending(10, Captured.AddDays(1))).Event.SourceEventId);
                reopened.Enqueue(item);
                Assert.Equal(1, reopened.Summary().Pending);
            }
        }
        finally
        {
            DeleteSqlite(path);
        }
    }

    [Fact]
    public void OutboxRejectsSameIdWithChangedPayload()
    {
        var path = Path.Combine(Path.GetTempPath(), $"keiba-outbox-{Guid.NewGuid():N}.sqlite");
        try
        {
            using (var outbox = new SqliteEventOutbox(path))
            {
                var item = JvLiveRecordParser.ParseOdds(Odds("09051030"), "realtime", "0B31", Captured);
                outbox.Enqueue(item);
                Assert.Throws<InvalidDataException>(() => outbox.Enqueue(item with { PayloadSha256 = new string('a', 64) }));
            }
        }
        finally
        {
            DeleteSqlite(path);
        }
    }

    [Theory]
    [InlineData(HttpStatusCode.TooManyRequests, true)]
    [InlineData(HttpStatusCode.InternalServerError, true)]
    [InlineData(HttpStatusCode.BadRequest, false)]
    [InlineData(HttpStatusCode.Unauthorized, false)]
    [InlineData(HttpStatusCode.Conflict, false)]
    [InlineData(HttpStatusCode.UnprocessableEntity, false)]
    public async Task ClassifiesRetryableAndDeadResponsesWithoutLeakingBodyOrToken(HttpStatusCode status, bool transient)
    {
        using var http = new HttpClient(new ErrorHandler(status));
        var client = new LaravelEventClient(http, new Uri("http://localhost:8080"), "private-token");
        var error = await Assert.ThrowsAsync<EventIngestApiException>(() => client.SendAsync(
            [JvLiveRecordParser.ParseOdds(Odds("09051030"), "realtime", "0B31", Captured)], CancellationToken.None));
        Assert.Equal(transient, error.IsTransient);
        Assert.DoesNotContain("private-token", error.Message);
        Assert.DoesNotContain("private-payload", error.Message);
    }

    [Fact]
    public void BackfillPlannerEnforcesAnalysisBoundaryAndInclusiveRange()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => HistoricalRangePlanner.Dates(new(2007, 12, 31), new(2008, 1, 1)));
        Assert.Equal([new DateOnly(2008, 1, 1), new DateOnly(2008, 1, 2)],
            HistoricalRangePlanner.Dates(new(2008, 1, 1), new(2008, 1, 2)));
    }

    [Fact]
    public async Task SenderRetriesAfterSuccessBeforeLocalAckAndEventuallyMarksSent()
    {
        var path = Path.Combine(Path.GetTempPath(), $"keiba-outbox-{Guid.NewGuid():N}.sqlite");
        try
        {
            using (var outbox = new SqliteEventOutbox(path))
            {
                outbox.Enqueue(JvLiveRecordParser.ParseOdds(Odds("09051030"), "realtime", "0B31", Captured));
                var clock = new MutableClock(Captured);
                var sink = new CrashAfterRemoteSuccessSink();
                await new FlushOutbox(outbox, sink, clock).RunAsync();
                Assert.Equal(1, outbox.Summary().Pending);
                clock.Now = Captured.AddHours(2);
                await new FlushOutbox(outbox, sink, clock).RunAsync();
                Assert.Equal(1, outbox.Summary().Sent);
                Assert.Equal(2, sink.Calls);
            }
        }
        finally
        {
            DeleteSqlite(path);
        }
    }

    [Fact]
    public void BackfillCoverageUpdateIsIdempotentAcrossReopen()
    {
        var path = Path.Combine(Path.GetTempPath(), $"keiba-outbox-{Guid.NewGuid():N}.sqlite");
        try
        {
            using (var outbox = new SqliteEventOutbox(path))
            {
                outbox.RecordCoverage(new(2008, 1, 1), "no_data", 0, Captured);
                outbox.RecordCoverage(new(2008, 1, 1), "available", 12, Captured.AddMinutes(1));
            }
            using (var reopened = new SqliteEventOutbox(path))
            {
                var summary = reopened.CoverageSummary();
                Assert.Equal(1, summary.Available);
                Assert.Equal(0, summary.NoData);
                Assert.Equal("2008-01-01", summary.FirstDate);
                Assert.Equal(summary.FirstDate, summary.LastDate);
            }
        }
        finally
        {
            DeleteSqlite(path);
        }
    }

    private static byte[] Odds(string announcement)
    {
        var record = Blank(962, "O1");
        Write(record, 2, "1");
        Write(record, 3, "20260905");
        Race(record, 11);
        Write(record, 27, announcement);
        Write(record, 35, "1818777");
        Write(record, 43, "01123401");
        Write(record, 267, "010120024001");
        return record;
    }

    private static byte[] BodyWeight()
    {
        var record = Blank(847, "WH");
        Write(record, 2, "1");
        Write(record, 3, "20260905");
        Race(record, 11);
        Write(record, 27, "09050900");
        Write(record, 35, "01");
        Write(record, 73, "478-006");
        return record;
    }

    private static byte[] Weather()
    {
        var record = Blank(42, "WE");
        Write(record, 2, "1");
        Write(record, 3, "20260905");
        Write(record, 11, "20260905010305");
        Write(record, 25, "09050800");
        Write(record, 33, "2234000");
        return record;
    }

    private static byte[] RunnerStatus(char category)
    {
        var record = Blank(78, "AV");
        Write(record, 2, category.ToString());
        Write(record, 3, "20260905");
        Race(record, 11);
        Write(record, 27, "09050930");
        Write(record, 35, "01");
        Write(record, 73, "001");
        return record;
    }

    private static byte[] JockeyChange()
    {
        var record = Blank(161, "JC");
        Write(record, 2, "1");
        Write(record, 3, "20260905");
        Race(record, 11);
        Write(record, 27, "09050935");
        Write(record, 35, "01");
        Write(record, 76, "54321");
        Write(record, 119, "12345");
        return record;
    }

    private static void Race(byte[] record, int offset) => Write(record, offset, "2026090501030501");

    private static byte[] Blank(int length, string type)
    {
        var record = Enumerable.Repeat((byte)' ', length).ToArray();
        Write(record, 0, type);
        Write(record, length - 2, "\r\n");
        return record;
    }

    private static void Write(byte[] record, int offset, string value) => Encoding.ASCII.GetBytes(value).CopyTo(record, offset);

    private static void DeleteSqlite(string path)
    {
        foreach (var suffix in new[] { "", "-wal", "-shm" }) if (File.Exists(path + suffix)) File.Delete(path + suffix);
    }

    private sealed class ErrorHandler(HttpStatusCode status) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(new HttpResponseMessage(status) { Content = new StringContent("private-payload") });
    }

    private sealed class MutableClock(DateTimeOffset now) : TimeProvider
    {
        public DateTimeOffset Now { get; set; } = now;
        public override DateTimeOffset GetUtcNow() => Now;
    }

    private sealed class CrashAfterRemoteSuccessSink : ILiveEventSink
    {
        public int Calls { get; private set; }

        public Task<EventIngestResult> SendAsync(IReadOnlyList<JvEvent> events, CancellationToken cancellationToken)
        {
            Calls++;
            if (Calls == 1) throw new HttpRequestException("Synthetic crash after the remote idempotent commit.");
            return Task.FromResult(new EventIngestResult(1, 0, 1, 0));
        }
    }
}
