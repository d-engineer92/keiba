using System.Net;
using System.Text;
using System.Text.Json;
using Keiba.Collector.Core;
using Keiba.Collector.JvLink;

namespace Keiba.Collector.Tests;

public class ScheduleTests
{
    [Fact]
    public void ParsesSyntheticRecordWithoutInventingAnUpdateTime()
    {
        var parsed = YsRecordParser.Parse(Record());
        Assert.Equal("01", parsed.Schedule.VenueCode);
        Assert.Equal(new DateOnly(2026, 9, 5), parsed.Schedule.RaceDate);
        Assert.Equal(new DateOnly(2026, 9, 1), parsed.MadeOn);
        Assert.Equal(3, parsed.Schedule.MeetingNo);
        Assert.Equal(5, parsed.Schedule.MeetingDay);
        Assert.Null(parsed.Schedule.SourceUpdatedAt);
        Assert.Equal("scheduled", parsed.Schedule.Status);
    }

    [Fact]
    public void ReadsAsciiHeaderWithoutReencodingUnusedUnicodeTitles()
    {
        var header = Encoding.ASCII.GetString(Record(), 0, 26);
        var record = header + "競走名\uFFFD" + "\r\n";
        Assert.Equal(YsRecordParser.Parse(Record()), YsRecordParser.ParseJvRead(record, 382));
        Assert.Throws<FormatException>(() => YsRecordParser.ParseJvRead(record, 381));
        Assert.Throws<FormatException>(() => YsRecordParser.ParseJvRead(record.TrimEnd(), 382));
        Assert.Throws<FormatException>(() => YsRecordParser.ParseJvRead("Ｙ" + record[1..], 382));
    }

    [Fact]
    public void ParsesNullableFieldsAndUnknownVenueWithoutGuessingAName()
    {
        var record = Record();
        Write(record, 19, "9900  ");
        var result = YsRecordParser.Parse(record).Schedule;
        Assert.Equal("99", result.VenueCode);
        Assert.Null(result.VenueName);
        Assert.Null(result.MeetingNo);
        Assert.Null(result.MeetingDay);
    }

    [Theory]
    [InlineData('1', "scheduled")]
    [InlineData('2', "scheduled")]
    [InlineData('3', "completed")]
    [InlineData('9', "cancelled")]
    [InlineData('0', "deleted")]
    public void MapsOnlyDocumentedCategories(char category, string expected)
    {
        var record = Record();
        record[2] = (byte)category;
        Assert.Equal(expected, YsRecordParser.Parse(record).Schedule.Status);
    }

    [Theory]
    [InlineData(0, "XX")]
    [InlineData(2, "7")]
    [InlineData(3, "20260230")]
    [InlineData(11, "20261305")]
    [InlineData(19, "A1")]
    [InlineData(21, "x3")]
    [InlineData(380, "  ")]
    public void RejectsMalformedRecords(int offset, string replacement)
    {
        var record = Record();
        Write(record, offset, replacement);
        Assert.Throws<FormatException>(() => YsRecordParser.Parse(record));
    }

    [Fact]
    public void RejectsTruncatedRecords() => Assert.Throws<FormatException>(() => YsRecordParser.Parse(Record()[..381]));

    [Fact]
    public async Task FakeClientProducesDeterministicBatchesAndCanBeReplayed()
    {
        var sink = new RecordingSink();
        var source = new FakeJvLinkClient([YsRecordParser.Parse(Record()).Schedule]);
        var sync = new SyncSchedules(source, sink, new FixedClock());
        var from = new DateTime(2026, 8, 1);
        await sync.RunAsync(from);
        await sync.RunAsync(from);
        Assert.Equal(from, source.LastFrom);
        Assert.Equal(JsonSerializer.Serialize(sink.Batches[0]), JsonSerializer.Serialize(sink.Batches[1]));
        Assert.Equal("01", sink.Batches[0].Schedules[0].VenueCode);
    }

    [Fact]
    public async Task EmptyAcquisitionDoesNotSendAnInvalidEmptyBatch()
    {
        var sink = new RecordingSink();
        var result = await new SyncSchedules(new FakeJvLinkClient([]), sink, new FixedClock()).RunAsync(new(2026, 8, 1));
        Assert.Empty(sink.Batches);
        Assert.Equal(0, result.Received);
    }

    [Fact]
    public async Task BatchesRequestsAtTheApiLimit()
    {
        var schedule = YsRecordParser.Parse(Record()).Schedule;
        var sink = new RecordingSink();
        var result = await new SyncSchedules(new FakeJvLinkClient(Enumerable.Repeat(schedule, 1001).ToArray()), sink, new FixedClock())
            .RunAsync(new(2026, 8, 1));
        Assert.Equal([1000, 1], sink.Batches.Select(x => x.Schedules.Count));
        Assert.Equal(1001, result.Received);
    }

    [Fact]
    public async Task SerializesContractWithAuthenticationAndNullableFields()
    {
        var handler = new StubHandler(HttpStatusCode.OK, "{\"received\":1,\"inserted\":1,\"updated\":0,\"unchanged\":0}");
        using var http = new HttpClient(handler);
        var client = new LaravelScheduleClient(http, new Uri("http://localhost:8080"), "synthetic-token");
        var result = await client.SendAsync(Batch(), CancellationToken.None);
        Assert.Equal(1, result.Inserted);
        Assert.Equal("Bearer synthetic-token", handler.Authorization);
        Assert.Equal("/api/internal/v1/jvlink/schedules", handler.Path);
        using var json = JsonDocument.Parse(handler.Body!);
        var schedule = json.RootElement.GetProperty("schedules")[0];
        Assert.Equal("01", schedule.GetProperty("venue_code").GetString());
        Assert.Equal("2026-09-05", schedule.GetProperty("race_date").GetString());
        Assert.Equal(JsonValueKind.Null, schedule.GetProperty("source_updated_at").ValueKind);
        Assert.True(json.RootElement.TryGetProperty("captured_at", out _));
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, false)]
    [InlineData(HttpStatusCode.Conflict, false)]
    [InlineData(HttpStatusCode.UnprocessableEntity, false)]
    [InlineData(HttpStatusCode.InternalServerError, true)]
    [InlineData(HttpStatusCode.ServiceUnavailable, true)]
    public async Task ReportsApiFailuresWithoutPayloadOrToken(HttpStatusCode status, bool transient)
    {
        using var http = new HttpClient(new StubHandler(status, "sensitive response"));
        var client = new LaravelScheduleClient(http, new Uri("http://localhost:8080"), "synthetic-token");
        var error = await Assert.ThrowsAsync<IngestApiException>(() => client.SendAsync(Batch(), CancellationToken.None));
        Assert.Equal(status, error.StatusCode);
        Assert.Equal(transient, error.IsTransient);
        Assert.DoesNotContain("sensitive", error.Message);
        Assert.DoesNotContain("synthetic-token", error.Message);
    }

    [Theory]
    [InlineData("null")]
    [InlineData("{\"received\":1,\"inserted\":0,\"updated\":0,\"unchanged\":0}")]
    public async Task RejectsInvalidSuccessCounts(string body)
    {
        using var http = new HttpClient(new StubHandler(HttpStatusCode.OK, body));
        var client = new LaravelScheduleClient(http, new Uri("http://localhost:8080"), "synthetic-token");
        await Assert.ThrowsAsync<InvalidDataException>(() => client.SendAsync(Batch(), CancellationToken.None));
    }

    private static ScheduleBatch Batch() => new(new FixedClock().GetUtcNow(), [YsRecordParser.Parse(Record()).Schedule]);
    private static byte[] Record()
    {
        var record = Enumerable.Repeat((byte)' ', 382).ToArray();
        Write(record, 0, "YS220260901202609050103056");
        Write(record, 380, "\r\n");
        return record;
    }
    private static void Write(byte[] record, int offset, string value) => Encoding.ASCII.GetBytes(value).CopyTo(record, offset);

    private sealed class FakeJvLinkClient(IReadOnlyList<Schedule> schedules) : IJvLinkClient
    {
        public DateTime LastFrom { get; private set; }
        public IReadOnlyList<Schedule> ReadSchedules(DateTime from, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            LastFrom = from;
            return schedules;
        }
    }
    private sealed class RecordingSink : IScheduleSink
    {
        public List<ScheduleBatch> Batches { get; } = [];
        public Task<IngestResult> SendAsync(ScheduleBatch batch, CancellationToken cancellationToken)
        {
            Batches.Add(batch);
            return Task.FromResult(new IngestResult(batch.Schedules.Count, batch.Schedules.Count, 0, 0));
        }
    }
    private sealed class FixedClock : TimeProvider
    {
        public override DateTimeOffset GetUtcNow() => new(2026, 9, 5, 0, 0, 0, TimeSpan.Zero);
    }
    private sealed class StubHandler(HttpStatusCode status, string response) : HttpMessageHandler
    {
        public string? Body { get; private set; }
        public string? Authorization { get; private set; }
        public string? Path { get; private set; }
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Body = await request.Content!.ReadAsStringAsync(cancellationToken);
            Authorization = request.Headers.Authorization!.ToString();
            Path = request.RequestUri!.AbsolutePath;
            return new HttpResponseMessage(status) { Content = new StringContent(response, Encoding.UTF8, "application/json") };
        }
    }
}
