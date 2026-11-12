using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Labyrinthe.LabPay.Tests.Support;

/// <summary>Requête reçue par le serveur simulé.</summary>
public sealed record RecordedRequest(HttpMethod Method, Uri Uri, IReadOnlyDictionary<string, string> Headers, string? Body)
{
    public string Path => Uri.AbsolutePath;

    public string Query => Uri.Query.TrimStart('?');

    public JsonElement Json => JsonDocument.Parse(Body ?? "null").RootElement.Clone();

    public string? Header(string name) => Headers.TryGetValue(name, out var value) ? value : null;
}

/// <summary>Serveur HTTP simulé : une file de réponses, chaque requête est enregistrée.</summary>
public sealed class MockHttp : HttpMessageHandler
{
    private readonly Queue<Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>> responses = new();

    public List<RecordedRequest> Requests { get; } = new();

    public RecordedRequest Last => Requests[^1];

    public MockHttp Respond(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> response)
    {
        responses.Enqueue(response);
        return this;
    }

    public MockHttp Json(int status, string json, params (string Name, string Value)[] headers) =>
        Respond((_, _) => Task.FromResult(Responses.Json(status, json, headers)));

    public MockHttp Data(int status, string dataJson, params (string Name, string Value)[] headers) =>
        Json(status, "{\"data\":" + dataJson + "}", headers);

    public MockHttp Error(int status, string code, params (string Name, string Value)[] headers) =>
        Json(status, "{\"message\":\"Message en français\",\"code\":\"" + code + "\"}", headers);

    public MockHttp Raw(int status, string body) =>
        Respond((_, _) => Task.FromResult(new HttpResponseMessage((HttpStatusCode)status) { Content = new StringContent(body) }));

    public MockHttp NetworkError() => Respond((_, _) => throw new HttpRequestException("Connection refused (api.example.test:443)"));

    /// <summary>Ne répond jamais : seul le délai (ou l'annulation) termine la requête.</summary>
    public MockHttp Hang() => Respond(async (_, token) =>
    {
        await Task.Delay(Timeout.Infinite, token);
        throw new InvalidOperationException("unreachable");
    });

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var header in request.Headers)
        {
            // User-Agent est une suite de produits séparés par des espaces, pas une liste.
            headers[header.Key] = string.Join(header.Key == "User-Agent" ? " " : ", ", header.Value);
        }

        string? body = null;
        if (request.Content is not null)
        {
            foreach (var header in request.Content.Headers)
            {
                headers[header.Key] = string.Join(", ", header.Value);
            }

            body = await request.Content.ReadAsStringAsync(cancellationToken);
        }

        Requests.Add(new RecordedRequest(request.Method, request.RequestUri!, headers, body));
        if (responses.Count == 0)
        {
            throw new InvalidOperationException($"No response queued for {request.Method} {request.RequestUri}.");
        }

        return await responses.Dequeue()(request, cancellationToken);
    }
}

public static class Responses
{
    public static HttpResponseMessage Json(int status, string json, params (string Name, string Value)[] headers)
    {
        var response = new HttpResponseMessage((HttpStatusCode)status) { Content = new StringContent(json, Encoding.UTF8, "application/json") };
        response.Headers.TryAddWithoutValidation("X-Labyrinthe-Mode", "test");
        foreach (var (name, value) in headers)
        {
            if (!response.Headers.TryAddWithoutValidation(name, value))
            {
                response.Content.Headers.TryAddWithoutValidation(name, value);
            }
        }

        return response;
    }
}

/// <summary>Client branché sur le serveur simulé ; les attentes entre relances sont enregistrées, pas subies.</summary>
public sealed class Harness : IDisposable
{
    public const string Key = "test_abcdefghijklmnop";

    public Harness(Action<LabPayClientOptions>? configure = null)
    {
        var options = new LabPayClientOptions { ApiKey = Key, BaseUrl = "https://api.example.test/api" };
        configure?.Invoke(options);
        Client = new LabPayClient(options, new HttpClient(Http));
        Client.Transport.Delay = (delay, _) =>
        {
            Sleeps.Add(delay);
            return Task.CompletedTask;
        };
        Client.Transport.Jitter = () => 1.0;
    }

    public MockHttp Http { get; } = new();

    public LabPayClient Client { get; }

    public List<TimeSpan> Sleeps { get; } = new();

    public IReadOnlyList<RecordedRequest> Requests => Http.Requests;

    public void Dispose() => Client.Dispose();
}

/// <summary>Paiements et corps JSON de test.</summary>
public static class Fixtures
{
    /// <summary>Paiement de l'API ; <paramref name="overrides"/> remplace des champs (JSON brut).</summary>
    public static string Payment(params (string Key, string Json)[] overrides)
    {
        var fields = new List<(string Key, string Json)>
        {
            ("id", "\"DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6\""),
            ("reference", "\"ORDER-42\""),
            ("operation", "\"deposit\""),
            ("livemode", "false"),
            ("status", "\"pending\""),
            ("gateway_status", "\"ACCEPTED\""),
            ("amount", "\"1000.00\""),
            ("fee", "\"35.00\""),
            ("total_amount", "\"1035.00\""),
            ("currency", "\"CDF\""),
            ("country", "\"COD\""),
            ("phone", "\"0813456789\""),
            ("provider", "\"VODACOM_MPESA_COD\""),
            ("provider_transaction_id", "null"),
            ("failure", "null"),
            ("metadata", "{\"orderId\":\"42\"}"),
            ("created_at", "\"2026-11-11T10:00:00+00:00\""),
            ("completed_at", "null"),
            ("refunded_amount", "\"0.00\""),
        };
        foreach (var (key, json) in overrides)
        {
            var index = fields.FindIndex(f => f.Key == key);
            if (index >= 0)
            {
                fields[index] = (key, json);
            }
            else
            {
                fields.Add((key, json));
            }
        }

        return "{" + string.Join(",", fields.Select(f => "\"" + f.Key + "\":" + f.Json)) + "}";
    }

    public static string PageOf(string items, int current, int last, int perPage, int total) =>
        "{\"data\":[" + items + "],\"links\":{},\"meta\":{\"current_page\":" + current + ",\"last_page\":" + last +
        ",\"per_page\":" + perPage + ",\"total\":" + total + "}}";
}
