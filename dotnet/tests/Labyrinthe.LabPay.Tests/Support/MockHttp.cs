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
