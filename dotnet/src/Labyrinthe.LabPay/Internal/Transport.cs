using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Labyrinthe.LabPay.Internal;

/// <summary>
/// Quand une requête peut être renvoyée automatiquement. Mêmes règles que les SDK PHP et Node.
/// <list type="bullet">
/// <item><see cref="Safe"/> : le rejeu ne produit pas deux fois un effet (GET, predict-provider, sync, et dépôts /
/// envois / remboursements avec <c>reference</c>). Relancée sur erreur réseau, 5xx et 429 du limiteur.</item>
/// <item><see cref="ThrottledOnly"/> : rotations, renvoi de notification, annulation, paiement sans référence.
/// Relancée seulement sur 429 du limiteur.</item>
/// </list>
/// Dans les deux modes, un 503 qui refuse la requête avant tout traitement (MAINTENANCE, DEPOSITS_DISABLED…) n'est
/// relancé qu'après un Retry-After au plus égal à MaxRetryWait ; DAILY_LIMIT_REACHED jamais.
/// </summary>
internal enum RetryMode
{
    Safe,
    ThrottledOnly,
}

/// <summary>Réponse 2xx (ou 422 portant un paiement).</summary>
internal sealed record ApiResponse(int Status, JsonElement Body, IReadOnlyDictionary<string, string> Headers)
{
    /// <summary>Le champ <c>data</c> du corps (indéfini s'il manque).</summary>
    public JsonElement Data =>
        Body.ValueKind == JsonValueKind.Object && Body.TryGetProperty("data", out var data) ? data : default;
}

/// <summary>
/// Envoi des requêtes, politique de relance et conversion des erreurs. Mêmes règles que
/// <c>php/src/Internal/Transport.php</c> et <c>node/src/internal/transport.ts</c>. Usage interne.
/// </summary>
internal sealed class Transport
{
    public const int MaxRetriesLimit = 10;

    /// <summary>503 qui refusent la requête avant tout traitement : relancés seulement après un Retry-After.</summary>
    public static readonly IReadOnlyCollection<string> RefusedBeforeProcessing = new HashSet<string>(StringComparer.Ordinal)
    {
        "MAINTENANCE",
        "API_DISABLED",
        "DEPOSITS_DISABLED",
        "WITHDRAWALS_DISABLED",
        "PROVIDER_TEMPORARILY_UNAVAILABLE",
        "V2_DISABLED",
        "TEST_MODE_DISABLED",
    };

    private const double BaseDelayMs = 500;
    private const double MaxBackoffMs = 8_000;

    private static readonly Random RandomSource = new();

    private static readonly IReadOnlyDictionary<string, string> EmptyHeaders = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

    private readonly string apiKey;
    private readonly HttpClient http;
    private readonly string userAgent;
    private readonly ILabPayLogger? logger;

    public Transport(string apiKey, string baseUrl, TimeSpan timeout, int maxRetries, TimeSpan maxRetryWait, HttpClient http, ILabPayLogger? logger, string? userAgentSuffix)
    {
        this.apiKey = apiKey;
        BaseUrl = baseUrl;
        Timeout = timeout;
        MaxRetries = maxRetries;
        MaxRetryWait = maxRetryWait;
        this.http = http;
        this.logger = logger;
        userAgent = string.IsNullOrWhiteSpace(userAgentSuffix) ? DefaultUserAgent : DefaultUserAgent + " " + userAgentSuffix!.Trim();
    }

    public static string DefaultUserAgent { get; } = BuildUserAgent();

    public string BaseUrl { get; }

    public TimeSpan Timeout { get; }

    public int MaxRetries { get; }

    public TimeSpan MaxRetryWait { get; }

    /// <summary>Statut et en-têtes de la dernière réponse reçue.</summary>
    public ResponseInfo? LastResponse { get; private set; }

    /// <summary>Attente entre deux tentatives (remplacée dans les tests).</summary>
    public Func<TimeSpan, CancellationToken, Task> Delay { get; set; } = Task.Delay;

    /// <summary>Source de la gigue, entre 0 et 1 (remplacée dans les tests).</summary>
    public Func<double> Jitter { get; set; } = SharedRandom;

    /// <summary>
    /// Un hôte nu désigne <c>/api</c>, un <c>…/api/V2</c> est accepté comme <c>…/api</c>, puis <c>/V2</c> est ajouté.
    /// </summary>
    public static string NormalizeBaseUrl(string baseUrl)
    {
        const string Message = "BaseUrl must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api.";
        if (!Uri.TryCreate(baseUrl.Trim(), UriKind.Absolute, out var uri)
            || (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps)
            || uri.Host.Length == 0)
        {
            throw new InvalidArgumentException(Message);
        }

        var path = uri.AbsolutePath.TrimEnd('/');
        var root = uri.GetLeftPart(UriPartial.Authority) + path;
        if (path.Length == 0)
        {
            root += "/api";
        }

        if (root.EndsWith("/v2", StringComparison.OrdinalIgnoreCase))
        {
            root = root.Substring(0, root.Length - 3);
        }

        return root + "/V2";
    }

    public static void ValidateOptions(TimeSpan timeout, int maxRetries, TimeSpan maxRetryWait)
    {
        if (timeout <= TimeSpan.Zero && timeout != System.Threading.Timeout.InfiniteTimeSpan)
        {
            throw new InvalidArgumentException("Timeout must be greater than zero.");
        }

        if (maxRetries is < 0 or > MaxRetriesLimit)
        {
            throw new InvalidArgumentException($"MaxRetries must be between 0 and {MaxRetriesLimit}.");
        }

        if (maxRetryWait < TimeSpan.Zero)
        {
            throw new InvalidArgumentException("MaxRetryWait cannot be negative.");
        }
    }

    public async Task<ApiResponse> SendAsync(
        HttpMethod method,
        string path,
        RetryMode mode,
        IReadOnlyList<KeyValuePair<string, string>>? query,
        byte[]? body,
        bool paymentOnRefusal,
        CancellationToken cancellationToken)
    {
        query ??= Array.Empty<KeyValuePair<string, string>>();
        var url = BuildUrl(path, query);
        var logPath = Redact.Path(path, query);

        for (var attempt = 0; ; attempt++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            Log(LabPayLogLevel.Debug, $"LabPay request {method} {logPath} (attempt {attempt + 1})");

            var status = 0;
            var content = Array.Empty<byte>();
            var headers = EmptyHeaders;
            string? failure = null;
            var timedOut = false;

            using (var request = BuildRequest(method, url, body))
            using (var attemptToken = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken))
            {
                if (Timeout != System.Threading.Timeout.InfiniteTimeSpan)
                {
                    attemptToken.CancelAfter(Timeout);
                }

                try
                {
                    using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, attemptToken.Token).ConfigureAwait(false);
                    status = (int)response.StatusCode;
                    headers = HeadersOf(response);
#if NET8_0_OR_GREATER
                    content = await response.Content.ReadAsByteArrayAsync(attemptToken.Token).ConfigureAwait(false);
#else
                    content = await response.Content.ReadAsByteArrayAsync().ConfigureAwait(false);
#endif
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    // Annulation demandée par l'appelant : jamais relancée, jamais convertie en délai dépassé.
                    throw new OperationCanceledException("The LabPay request was cancelled.", null, cancellationToken);
                }
                catch (OperationCanceledException)
                {
                    // Délai de la tentative dépassé (ou HttpClient.Timeout d'un client fourni).
                    timedOut = true;
                    failure = $"timed out after {(long)Timeout.TotalMilliseconds} ms";
                }
                catch (HttpRequestException exception)
                {
                    failure = Describe(exception);
                }
            }

            TimeSpan delay;
            if (failure is not null)
            {
                if (mode != RetryMode.Safe || attempt >= MaxRetries)
                {
                    Log(LabPayLogLevel.Error, $"LabPay network error on {method} {logPath} (timeout={(timedOut ? "true" : "false")})");
                    var suffix = mode == RetryMode.ThrottledOnly ? " The request may have been processed; it was not retried automatically." : string.Empty;
                    throw new ConnectionException($"Could not reach the LabPay API ({method} {path}): {failure}.{suffix}", timedOut);
                }

                delay = Backoff(attempt);
                Log(LabPayLogLevel.Warning, $"LabPay network error, retrying {method} {logPath} in {(long)delay.TotalMilliseconds} ms");
            }
            else
            {
                LastResponse = new ResponseInfo(status, headers);
                Log(LabPayLogLevel.Debug, $"LabPay response {method} {logPath}: HTTP {status}");
                if (status is >= 200 and < 300)
                {
                    return new ApiResponse(status, Decode(content, status), headers);
                }

                var parsed = Json.TryParse(content);
                if (paymentOnRefusal && status == 422 && HasPayment(parsed))
                {
                    return new ApiResponse(status, parsed!.Value, headers);
                }

                var error = ErrorMapper.FromResponse(status, parsed, headers);
                var wait = attempt < MaxRetries ? RetryDelay(mode, error, attempt) : null;
                if (wait is null)
                {
                    Log(LabPayLogLevel.Information, $"LabPay API error on {method} {logPath}: HTTP {status} {error.Code}");
                    throw error;
                }

                delay = wait.Value;
                Log(LabPayLogLevel.Warning, $"LabPay request failed, retrying {method} {logPath}: HTTP {status} {error.Code} in {(long)delay.TotalMilliseconds} ms");
            }

            await Delay(delay, cancellationToken).ConfigureAwait(false);
        }
    }

    public override string ToString() => $"Transport {{ ApiKey = {Redact.ApiKey(apiKey)}, BaseUrl = {BaseUrl}, MaxRetries = {MaxRetries} }}";

    /// <summary>Secondes (éventuellement décimales) ou date HTTP ; <see langword="null"/> si absent ou illisible.</summary>
    internal static TimeSpan? RetryAfter(IReadOnlyDictionary<string, string> headers)
    {
        if (!headers.TryGetValue("Retry-After", out var header) || header.Trim().Length == 0)
        {
            return null;
        }

        var value = header.Trim();
        if (value.All(c => c is (>= '0' and <= '9') or '.')
            && double.TryParse(value, NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture, out var seconds))
        {
            return TimeSpan.FromMilliseconds(Math.Round(seconds * 1000));
        }

        if (DateTimeOffset.TryParseExact(value, "r", CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var at))
        {
            var wait = at - DateTimeOffset.UtcNow;
            return wait > TimeSpan.Zero ? wait : TimeSpan.Zero;
        }

        return null;
    }

    private static double SharedRandom()
    {
        // Gigue, pas un secret : System.Random suffit (verrouillé : Random n'est pas thread-safe sous netstandard2.0).
        lock (RandomSource)
        {
#pragma warning disable CA5394 // Random n'est pas utilisé à des fins de sécurité.
            return RandomSource.NextDouble();
#pragma warning restore CA5394
        }
    }

    private static string Describe(Exception exception)
    {
        // Message court et sans requête d'URL ; l'exception d'origine n'est jamais chaînée.
        var inner = exception;
        while (inner.InnerException is not null)
        {
            inner = inner.InnerException;
        }

        var message = Redact.StripQueryStrings(inner.Message).Trim();
        var name = inner.GetType().Name;
        return message.Length > 0 ? $"{name}: {message}" : name;
    }

    private static Dictionary<string, string> HeadersOf(HttpResponseMessage response)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var header in response.Headers.Concat(response.Content.Headers))
        {
            headers[header.Key] = string.Join(", ", header.Value);
        }

        return headers;
    }

    private static string BuildUserAgent()
    {
        // ".NET 8.0.10" → ".NET/8.0.10" ; ".NET Framework 4.8.9" → ".NETFramework/4.8.9".
        var description = RuntimeInformation.FrameworkDescription.Trim();
        var space = description.LastIndexOf(' ');
        var product = space > 0 ? description.Substring(0, space).Replace(" ", string.Empty) : ".NET";
        var version = space > 0 ? description.Substring(space + 1) : Environment.Version.ToString();
        return $"labpay-dotnet/{LabPayClient.Version} {product}/{version}";
    }

    private static bool HasPayment(JsonElement? body) =>
        body is { ValueKind: JsonValueKind.Object } root
        && root.TryGetProperty("data", out var data)
        && data.ValueKind == JsonValueKind.Object
        && data.TryGetProperty("id", out _);

    private static JsonElement Decode(byte[] content, int status)
    {
        if (content.All(b => b is (byte)' ' or (byte)'\n' or (byte)'\r' or (byte)'\t'))
        {
            return Json.TryParse(Encoding.UTF8.GetBytes("{}"))!.Value;
        }

        return Json.TryParse(content) ?? throw new UnexpectedResponseException("Unexpected API response: the body is not valid JSON.", status);
    }

    private string BuildUrl(string path, IReadOnlyList<KeyValuePair<string, string>> query)
    {
        if (query.Count == 0)
        {
            return BaseUrl + path;
        }

        var builder = new StringBuilder(BaseUrl).Append(path).Append('?');
        for (var i = 0; i < query.Count; i++)
        {
            if (i > 0)
            {
                builder.Append('&');
            }

            builder.Append(Uri.EscapeDataString(query[i].Key)).Append('=').Append(Uri.EscapeDataString(query[i].Value));
        }

        return builder.ToString();
    }

    private HttpRequestMessage BuildRequest(HttpMethod method, string url, byte[]? body)
    {
        var request = new HttpRequestMessage(method, url);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Headers.TryAddWithoutValidation("User-Agent", userAgent);
        if (body is not null)
        {
            // Sérialisé une seule fois : chaque relance renvoie exactement les mêmes octets.
            request.Content = new ByteArrayContent(body);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json") { CharSet = "utf-8" };
        }

        return request;
    }

    /// <summary>Délai avant la prochaine tentative ; <see langword="null"/> quand l'erreur doit être levée.</summary>
    private TimeSpan? RetryDelay(RetryMode mode, ApiException error, int attempt)
    {
        var retryAfter = RetryAfter(error.Headers);

        // Refusée avant traitement : seulement quand l'API annonce la réouverture.
        if (RefusedBeforeProcessing.Contains(error.Code))
        {
            return retryAfter is { } reopen && reopen <= MaxRetryWait ? reopen : null;
        }

        // Seulement le limiteur : DAILY_LIMIT_REACHED est une limite métier.
        if (error.StatusCode == 429)
        {
            if (error.Code != ErrorMapper.TooManyRequests)
            {
                return null;
            }

            if (retryAfter is { } wait)
            {
                return wait <= MaxRetryWait ? wait : null;
            }

            return Backoff(attempt);
        }

        return mode == RetryMode.Safe && error.StatusCode >= 500 ? Backoff(attempt) : null;
    }

    /// <summary>Backoff exponentiel avec gigue : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné.</summary>
    private TimeSpan Backoff(int attempt)
    {
        var delay = Math.Min(MaxBackoffMs, BaseDelayMs * Math.Pow(2, attempt));
        var jittered = TimeSpan.FromMilliseconds(delay * (0.5 + (Jitter() * 0.5)));
        return jittered < MaxRetryWait ? jittered : MaxRetryWait;
    }

    private void Log(LabPayLogLevel level, string message)
    {
        if (logger is not null && logger.IsEnabled(level))
        {
            logger.Log(level, message);
        }
    }
}
