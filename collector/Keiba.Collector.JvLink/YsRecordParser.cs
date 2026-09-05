using System.Globalization;
using System.Text;
using Keiba.Collector.Core;

namespace Keiba.Collector.JvLink;

public sealed record ParsedSchedule(DateOnly MadeOn, Schedule Schedule);

public static class YsRecordParser
{
    // Official JV-Data 4.9.0.1: YS layout and code table 2001 (central venues).
    // These are external identifiers, never database primary keys.
    private static readonly IReadOnlyDictionary<string, string> VenueNames = new Dictionary<string, string>
    {
        ["01"] = "札幌競馬場", ["02"] = "函館競馬場", ["03"] = "福島競馬場", ["04"] = "新潟競馬場",
        ["05"] = "東京競馬場", ["06"] = "中山競馬場", ["07"] = "中京競馬場", ["08"] = "京都競馬場",
        ["09"] = "阪神競馬場", ["10"] = "小倉競馬場"
    };

    // JVRead returns Unicode, but only the first 26 ASCII bytes are needed here.
    // Do not round-trip the unused Japanese race titles through the process code page.
    public static ParsedSchedule ParseJvRead(string record, int sourceByteCount)
    {
        if (sourceByteCount != 382 || record.Length < 28 || !record.EndsWith("\r\n", StringComparison.Ordinal))
            throw new FormatException("Invalid JVRead YS length or terminator.");
        foreach (var value in record.AsSpan(0, 26))
            if (value > 127) throw new FormatException("Invalid YS ASCII header.");
        return ParseHeader(Encoding.ASCII.GetBytes(record[..26]));
    }

    public static ParsedSchedule Parse(ReadOnlySpan<byte> record)
    {
        if (record.Length != 382 || record[0] != 'Y' || record[1] != 'S'
            || record[380] != '\r' || record[381] != '\n')
            throw new FormatException("Invalid YS record type, length or terminator.");
        return ParseHeader(record[..26]);
    }

    private static ParsedSchedule ParseHeader(ReadOnlySpan<byte> record)
    {
        if (record[0] != 'Y' || record[1] != 'S') throw new FormatException("Invalid YS record type.");
        var status = record[2] switch
        {
            (byte)'1' or (byte)'2' => "scheduled", (byte)'3' => "completed",
            (byte)'9' => "cancelled", (byte)'0' => "deleted",
            _ => throw new FormatException("Unsupported YS data category.")
        };
        var madeOn = Date(record.Slice(3, 8));
        var raceDate = Date(record.Slice(11, 8));
        var code = AsciiDigits(record.Slice(19, 2));
        var meetingNo = OptionalNumber(record.Slice(21, 2));
        var meetingDay = OptionalNumber(record.Slice(23, 2));
        VenueNames.TryGetValue(code, out var name);
        // YS provides a creation DATE only; it does not provide a source update instant.
        return new(madeOn, new(code, name, raceDate, meetingNo, meetingDay, status, null));
    }

    private static DateOnly Date(ReadOnlySpan<byte> bytes)
    {
        if (!DateOnly.TryParseExact(AsciiDigits(bytes), "yyyyMMdd", CultureInfo.InvariantCulture,
            DateTimeStyles.None, out var date)) throw new FormatException("Invalid YS date.");
        return date;
    }

    private static int? OptionalNumber(ReadOnlySpan<byte> bytes)
    {
        if (bytes.SequenceEqual("  "u8) || bytes.SequenceEqual("00"u8)) return null;
        return int.Parse(AsciiDigits(bytes), CultureInfo.InvariantCulture);
    }

    private static string AsciiDigits(ReadOnlySpan<byte> bytes)
    {
        foreach (var value in bytes)
            if (value < '0' || value > '9') throw new FormatException("Invalid YS numeric field.");
        return Encoding.ASCII.GetString(bytes);
    }
}
