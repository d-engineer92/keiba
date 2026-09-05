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
            if (args.Length != 1 || !DateTime.TryParseExact(args[0], "yyyyMMddHHmmss",
                CultureInfo.InvariantCulture, DateTimeStyles.None, out var from))
            {
                Console.Error.WriteLine("Usage: Keiba.Collector.Cli <fromtime: yyyyMMddHHmmss>");
                return 2;
            }
            var endpoint = Environment.GetEnvironmentVariable("KEIBA_API_URL") ?? "http://localhost:8080";
            var token = Environment.GetEnvironmentVariable("KEIBA_INGEST_TOKEN") ?? "";
            using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromSeconds(60) };
            var sink = new LaravelScheduleClient(http, new Uri(endpoint), token);
            using var cancellation = new CancellationTokenSource();
            Console.CancelKeyPress += (_, e) => { e.Cancel = true; cancellation.Cancel(); };
            var sync = new SyncSchedules(new WindowsJvLinkClient(Console.Error.WriteLine), sink, TimeProvider.System);
            var result = sync.RunAsync(from, cancellation.Token).GetAwaiter().GetResult();
            Console.WriteLine(JsonSerializer.Serialize(result, LaravelScheduleClient.JsonOptions));
            return 0;
        }
        catch (Exception exception)
        {
            // Messages contain operation/status only; never dump vendor records or credentials.
            Console.Error.WriteLine(exception is JvLinkException or IngestApiException or FormatException or TimeoutException
                ? exception.Message : $"Collector failed: {exception.GetType().Name}.");
            return 1;
        }
    }
}
