using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using Keiba.Collector.Core;

namespace Keiba.Collector.JvLink;

[SupportedOSPlatform("windows")]
public sealed class WindowsJvLiveClient(Action<string>? log = null) : IJvRealtimeClient, IJvOddsHistoryClient, IDisposable
{
    private object? session;

    public IReadOnlyList<JvEvent> ReadEvents(string raceKey, DateTimeOffset capturedAt, CancellationToken cancellationToken)
    {
        ValidateRaceKey(raceKey);
        return WithSession(jv =>
        {
            var events = new List<JvEvent>();
            foreach (var bytes in ReadRecords(jv, "0B31", raceKey, cancellationToken))
                if (HasType(bytes, "O1")) events.Add(JvLiveRecordParser.ParseOdds(bytes, "realtime", "0B31", capturedAt));
            foreach (var bytes in ReadRecords(jv, "0B11", raceKey, cancellationToken))
            {
                if (HasType(bytes, "WH")) events.AddRange(JvLiveRecordParser.ParseBodyWeights(bytes, capturedAt));
                else if (HasType(bytes, "WE")) events.Add(JvLiveRecordParser.ParseWeather(bytes, capturedAt));
            }
            foreach (var bytes in ReadRecords(jv, "0B14", raceKey[..8], cancellationToken))
            {
                if (HasType(bytes, "WE")) events.Add(JvLiveRecordParser.ParseWeather(bytes, capturedAt));
                else if (HasType(bytes, "AV")) events.Add(JvLiveRecordParser.ParseRunnerStatus(bytes, capturedAt));
                else if (HasType(bytes, "JC")) events.Add(JvLiveRecordParser.ParseJockeyChange(bytes, capturedAt));
            }
            return events.DistinctBy(item => item.SourceEventId).ToArray();
        });
    }

    public IReadOnlyList<JvEvent> ReadWinPlaceHistory(string raceKey, DateTimeOffset capturedAt, CancellationToken cancellationToken)
    {
        ValidateRaceKey(raceKey);
        return WithSession(jv => ReadRecords(jv, "0B41", raceKey, cancellationToken)
                .Where(bytes => HasType(bytes, "O1"))
                .Select(bytes => JvLiveRecordParser.ParseOdds(bytes, "historical_timeseries", "0B41", capturedAt))
                .ToArray());
    }

    private T WithSession<T>(Func<object, T> action)
    {
        if (session is null)
        {
            EnsurePlatform();
            var type = Type.GetTypeFromProgID("JVDTLab.JVLink", throwOnError: true)!;
            dynamic jv = Activator.CreateInstance(type)!;
            try
            {
                Check("JVInit", (int)jv.JVInit("UNKNOWN"));
                session = jv;
            }
            catch
            {
                Marshal.FinalReleaseComObject(jv);
                throw;
            }
        }
        return action(session);
    }

    private IReadOnlyList<byte[]> ReadRecords(object instance, string dataSpec, string key, CancellationToken cancellationToken)
    {
        dynamic jv = instance;
        var openedSuccessfully = false;
        var failed = false;
        try
        {
            int opened = jv.JVRTOpen(dataSpec, key);
            log?.Invoke($"JVRTOpen spec={dataSpec} result={opened}");
            if (opened == -1) return [];
            Check("JVRTOpen", opened);
            openedSuccessfully = true;
            var records = new List<byte[]>();
            while (true)
            {
                cancellationToken.ThrowIfCancellationRequested();
                string buffer = "", filename = "";
                int count = jv.JVRead(ref buffer, 110000, ref filename);
                if (count == 0) break;
                if (count == -1) continue;
                if (count < 0) throw new JvLinkException("JVRead", count);
                records.Add(ToBytes(buffer, count));
            }
            log?.Invoke($"JVRTOpen spec={dataSpec} records={records.Count}");
            return records;
        }
        catch
        {
            failed = true;
            throw;
        }
        finally
        {
            try
            {
                if (openedSuccessfully)
                {
                    int closed = jv.JVClose();
                    if (!failed) Check("JVClose", closed);
                }
            }
            catch when (failed) { }
        }
    }

    private static byte[] ToBytes(string value, int expectedCount)
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        var encoding = Encoding.GetEncoding(932, new EncoderReplacementFallback("??"), DecoderFallback.ExceptionFallback);
        var bytes = encoding.GetBytes(value);
        if (bytes.Length != expectedCount)
            throw new FormatException("JVRead text could not be reconstructed at its declared byte length.");
        return bytes;
    }

    private static bool HasType(byte[] bytes, string type) => bytes.Length >= 2 && bytes[0] == type[0] && bytes[1] == type[1];

    private static void ValidateRaceKey(string raceKey)
    {
        if (raceKey.Length != 12 || !raceKey.All(char.IsAsciiDigit))
            throw new ArgumentException("A race key must be YYYYMMDDJJRR.", nameof(raceKey));
    }

    private static void EnsurePlatform()
    {
        if (Environment.Is64BitProcess) throw new PlatformNotSupportedException("The verified JV-Link registration requires x86.");
        if (Thread.CurrentThread.GetApartmentState() != ApartmentState.STA)
            throw new InvalidOperationException("JV-Link must be called on the STA thread.");
    }

    private static void Check(string operation, int code)
    {
        if (code != 0) throw new JvLinkException(operation, code);
    }

    public void Dispose()
    {
        if (session is not null)
        {
            Marshal.FinalReleaseComObject(session);
            session = null;
        }
    }
}
