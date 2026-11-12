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
