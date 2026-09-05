using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Keiba.Collector.Core;

namespace Keiba.Collector.JvLink;

public sealed class JvLinkException(string operation, int code) : Exception($"{operation} returned {code}.")
{
    public int ReturnCode { get; } = code;
}

[SupportedOSPlatform("windows")]
public sealed class WindowsJvLinkClient(Action<string>? log = null) : IJvLinkClient, IJvLinkSetupClient
{
    public IReadOnlyList<Schedule> ReadSchedules(DateTime from, CancellationToken cancellationToken)
        => ReadSchedulesCore(from.ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture), 1, cancellationToken);

    public IReadOnlyList<Schedule> ReadSetupSchedules(string fromTime, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(fromTime)) throw new ArgumentException("Setup fromtime is required.", nameof(fromTime));
        return ReadSchedulesCore(fromTime, 4, cancellationToken);
    }

    private IReadOnlyList<Schedule> ReadSchedulesCore(string fromTime, int option, CancellationToken cancellationToken)
    {
        if (Environment.Is64BitProcess) throw new PlatformNotSupportedException("The verified JV-Link registration requires x86.");
        if (Thread.CurrentThread.GetApartmentState() != ApartmentState.STA)
            throw new InvalidOperationException("JV-Link must be called on the STA thread.");
        var type = Type.GetTypeFromProgID("JVDTLab.JVLink", throwOnError: true)!;
        dynamic jv = Activator.CreateInstance(type)!;
        var initialized = false;
        var failed = false;
        try
        {
            Check("JVInit", (int)jv.JVInit("UNKNOWN"));
            initialized = true;
            int readCount = 0, downloadCount = 0;
            string lastTimestamp = "";
            int opened = jv.JVOpen("YSCH", fromTime, option,
                ref readCount, ref downloadCount, ref lastTimestamp);
            log?.Invoke($"JVOpen={opened} option={option} files={readCount} downloads={downloadCount}");
            if (opened == -1) return [];
            Check("JVOpen", opened);
            var timer = Stopwatch.StartNew();
            while (downloadCount > 0)
            {
                cancellationToken.ThrowIfCancellationRequested();
                int status = jv.JVStatus();
                if (status < 0) throw new JvLinkException("JVStatus", status);
                if (status >= downloadCount) break;
                if (timer.Elapsed > TimeSpan.FromMinutes(3)) throw new TimeoutException("JV-Link download timed out.");
                Thread.Sleep(100);
            }
            var latest = new Dictionary<(DateOnly Date, string Code), ParsedSchedule>();
            int readRecords = 0;
            while (true)
            {
                cancellationToken.ThrowIfCancellationRequested();
                if (timer.Elapsed > TimeSpan.FromMinutes(5)) throw new TimeoutException("JV-Link read timed out.");
                string buffer = "", filename = "";
                int count = jv.JVRead(ref buffer, 40000, ref filename);
                if (count == 0) break;
                if (count == -1) continue;
                if (count < 0) throw new JvLinkException("JVRead", count);
                if (!buffer.StartsWith("YS", StringComparison.Ordinal))
                {
                    log?.Invoke("Skipped a non-YS record.");
                    continue;
                }
                var parsed = YsRecordParser.ParseJvRead(buffer, count);
                var key = (parsed.Schedule.RaceDate, parsed.Schedule.VenueCode);
                if (!latest.TryGetValue(key, out var previous) || parsed.MadeOn >= previous.MadeOn)
                    latest[key] = parsed;
                readRecords++;
            }
            log?.Invoke($"YS records={readRecords} schedules={latest.Count}");
            return latest.Values.Select(x => x.Schedule).OrderBy(x => x.RaceDate).ThenBy(x => x.VenueCode, StringComparer.Ordinal).ToArray();
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
                if (initialized)
                {
                    int closed = jv.JVClose();
                    log?.Invoke($"JVClose={closed}");
                    if (!failed) Check("JVClose", closed);
                }
            }
            finally { Marshal.FinalReleaseComObject(jv); }
        }
    }

    private static void Check(string operation, int code)
    {
        if (code != 0) throw new JvLinkException(operation, code);
    }
}
