using System.Globalization;
using System.Text;
using Keiba.Collector.Core;

namespace Keiba.Collector.JvLink;

public static class JvLiveRecordParser
{
    private static readonly TimeSpan JapanOffset = TimeSpan.FromHours(9);

    public static JvEvent ParseOdds(byte[] record, string sourceKind, string dataSpec, DateTimeOffset capturedAt)
    {
        Validate(record, 962, "O1");
        var race = Race(record, 11);
        var announcement = Text(record, 27, 8);
        var published = Published(race.Date, announcement);
        var items = new List<IReadOnlyDictionary<string, object?>>();
        for (var index = 0; index < 28; index++)
        {
            AddWin(items, record, 43 + index * 8);
            AddPlace(items, record, 267 + index * 12);
        }
        var payload = new Dictionary<string, object?>
        {
            ["race_date"] = race.Date.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
            ["venue_code"] = race.VenueCode,
            ["race_no"] = race.RaceNo,
            ["jvlink_race_id"] = race.Key,
            ["source_kind"] = sourceKind,
            ["snapshot_at"] = published?.ToString("O"),
            ["items"] = items,
        };
        var stableKey = $"{race.Key}:O1:{Text(record, 2, 1)}:{announcement}";
        return JvEventFactory.Create("odds_snapshot", dataSpec, "O1", stableKey, published, capturedAt, payload);
    }

    public static IReadOnlyList<JvEvent> ParseBodyWeights(byte[] record, DateTimeOffset capturedAt)
    {
        Validate(record, 847, "WH");
        var race = Race(record, 11);
        var announcement = Text(record, 27, 8);
        var published = Published(race.Date, announcement);
        var events = new List<JvEvent>();
        for (var index = 0; index < 18; index++)
        {
            var offset = 35 + index * 45;
            var horse = Integer(record, offset, 2);
            if (horse is null) continue;
            var rawWeight = Text(record, offset + 38, 3);
            var sign = Text(record, offset + 41, 1);
            var rawDelta = Text(record, offset + 42, 3);
            int? weight = rawWeight is "000" or "999" ? null : ParseInteger(rawWeight);
            int? delta = string.IsNullOrWhiteSpace(rawDelta) ? null : ParseInteger(rawDelta);
            if (delta is not null && sign == "-") delta = -delta;
            string? status = rawWeight switch { "000" => "cancelled", "999" => "not_measured", _ => null };
            var payload = RacePayload(race);
            payload["horse_no"] = horse;
            payload["body_weight"] = weight;
            payload["body_weight_delta"] = delta;
            payload["source_status_code"] = status;
            events.Add(JvEventFactory.Create("body_weight", "0B11", "WH", $"{race.Key}:WH:{announcement}:{horse:00}", published, capturedAt, payload));
        }
        return events;
    }

    public static JvEvent ParseRunnerStatus(byte[] record, DateTimeOffset capturedAt)
    {
        Validate(record, 78, "AV");
        var race = Race(record, 11);
        var announcement = Text(record, 27, 8);
        var horse = RequiredInteger(record, 35, 2);
        var published = Published(race.Date, announcement);
        var payload = RacePayload(race);
        payload["horse_no"] = horse;
        payload["status_type"] = Text(record, 2, 1) == "1" ? "cancelled" : "excluded";
        payload["reason_code"] = NullIfBlank(Text(record, 73, 3));
        return JvEventFactory.Create("runner_status", "0B14", "AV", $"{race.Key}:AV:{Text(record, 2, 1)}:{announcement}:{horse:00}", published, capturedAt, payload);
    }

    public static JvEvent ParseJockeyChange(byte[] record, DateTimeOffset capturedAt)
    {
        Validate(record, 161, "JC");
        var race = Race(record, 11);
        var announcement = Text(record, 27, 8);
        var horse = RequiredInteger(record, 35, 2);
        var published = Published(race.Date, announcement);
        var payload = RacePayload(race);
        payload["horse_no"] = horse;
        payload["old_jockey_code"] = Code(record, 119, 5);
        payload["old_jockey_name"] = null;
        payload["new_jockey_code"] = Code(record, 76, 5);
        payload["new_jockey_name"] = null;
        return JvEventFactory.Create("jockey_change", "0B14", "JC", $"{race.Key}:JC:{announcement}:{horse:00}", published, capturedAt, payload);
    }

    public static JvEvent ParseWeather(byte[] record, DateTimeOffset capturedAt)
    {
        Validate(record, 42, "WE");
        var date = DateOnly.ParseExact(Text(record, 11, 8), "yyyyMMdd", CultureInfo.InvariantCulture);
        var venue = Text(record, 19, 2);
        var announcement = Text(record, 25, 8);
        var published = Published(date, announcement);
        var change = Text(record, 33, 1);
        var payload = new Dictionary<string, object?>
        {
            ["race_date"] = date.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
            ["venue_code"] = venue,
            ["change_type"] = change switch { "1" => "initial", "2" => "weather", "3" => "track", _ => "unknown" },
            ["weather"] = Code(record, 34, 1),
            ["turf_condition"] = Code(record, 35, 1),
            ["dirt_condition"] = Code(record, 36, 1),
        };
        var meeting = Text(record, 21, 4);
        return JvEventFactory.Create("weather_track", "0B14", "WE", $"{date:yyyyMMdd}{venue}{meeting}:WE:{announcement}:{change}", published, capturedAt, payload);
    }

    private static Dictionary<string, object?> RacePayload(RaceIdentity race) => new()
    {
        ["race_date"] = race.Date.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
        ["venue_code"] = race.VenueCode,
        ["race_no"] = race.RaceNo,
        ["jvlink_race_id"] = race.Key,
    };

    private static void AddWin(List<IReadOnlyDictionary<string, object?>> items, byte[] bytes, int offset)
    {
        var horse = Integer(bytes, offset, 2);
        if (horse is null) return;
        var (value, status) = Odds(Text(bytes, offset + 2, 4));
        items.Add(new Dictionary<string, object?>
        {
            ["bet_type"] = "win", ["horse_no"] = horse, ["odds"] = value,
            ["odds_min"] = null, ["odds_max"] = null, ["status"] = status,
        });
    }

    private static void AddPlace(List<IReadOnlyDictionary<string, object?>> items, byte[] bytes, int offset)
    {
        var horse = Integer(bytes, offset, 2);
        if (horse is null) return;
        var (minimum, minStatus) = Odds(Text(bytes, offset + 2, 4));
        var (maximum, maxStatus) = Odds(Text(bytes, offset + 6, 4));
        items.Add(new Dictionary<string, object?>
        {
            ["bet_type"] = "place", ["horse_no"] = horse, ["odds"] = null,
            ["odds_min"] = minimum, ["odds_max"] = maximum, ["status"] = minStatus ?? maxStatus,
        });
    }

    private static (decimal? Value, string? Status) Odds(string raw) => raw switch
    {
        "0000" => (null, "no_votes"),
        "----" => (null, "withdrawn_before_sale"),
        "****" => (null, "withdrawn_after_sale"),
        "9999" or "0999" => (decimal.Parse(raw, CultureInfo.InvariantCulture) / 10m, "capped"),
        _ when string.IsNullOrWhiteSpace(raw) => (null, "not_registered"),
        _ => (decimal.Parse(raw, CultureInfo.InvariantCulture) / 10m, null),
    };

    private static RaceIdentity Race(byte[] record, int offset)
    {
        var date = DateOnly.ParseExact(Text(record, offset, 8), "yyyyMMdd", CultureInfo.InvariantCulture);
        var venue = Text(record, offset + 8, 2);
        var meeting = Text(record, offset + 10, 4);
        var raceNo = RequiredInteger(record, offset + 14, 2);
        return new(date, venue, raceNo, $"{date:yyyyMMdd}{venue}{meeting}{raceNo:00}");
    }

    private static DateTimeOffset? Published(DateOnly raceDate, string monthDayTime)
    {
        if (string.IsNullOrWhiteSpace(monthDayTime) || monthDayTime.All(character => character == '0')) return null;
        if (monthDayTime.Length != 8 || !int.TryParse(monthDayTime, out _))
            throw new FormatException("A JV-Link announcement timestamp is invalid.");
        try
        {
            var month = int.Parse(monthDayTime[..2], CultureInfo.InvariantCulture);
            var year = month > raceDate.Month + 6 ? raceDate.Year - 1 : raceDate.Year;
            return new DateTimeOffset(year, month, int.Parse(monthDayTime[2..4], CultureInfo.InvariantCulture),
                int.Parse(monthDayTime[4..6], CultureInfo.InvariantCulture), int.Parse(monthDayTime[6..8], CultureInfo.InvariantCulture), 0, JapanOffset);
        }
        catch (ArgumentOutOfRangeException exception)
        {
            throw new FormatException("A JV-Link announcement timestamp is invalid.", exception);
        }
    }

    private static void Validate(byte[] record, int length, string type)
    {
        if (record.Length != length || Text(record, 0, 2) != type || record[^2] != 13 || record[^1] != 10)
            throw new FormatException($"JV-Link {type} record framing is invalid.");
    }

    private static string Text(byte[] bytes, int offset, int count) => Encoding.ASCII.GetString(bytes, offset, count);
    private static string? Code(byte[] bytes, int offset, int count) => NullIfBlank(Text(bytes, offset, count).Trim());
    private static string? NullIfBlank(string value) => string.IsNullOrWhiteSpace(value) || value.All(character => character == '0') ? null : value;
    private static int? Integer(byte[] bytes, int offset, int count) => int.TryParse(Text(bytes, offset, count), out var value) && value > 0 ? value : null;
    private static int RequiredInteger(byte[] bytes, int offset, int count) => Integer(bytes, offset, count) ?? throw new FormatException("A JV-Link numeric key is invalid.");
    private static int ParseInteger(string value) => int.Parse(value, CultureInfo.InvariantCulture);

    private sealed record RaceIdentity(DateOnly Date, string VenueCode, int RaceNo, string Key);
}
