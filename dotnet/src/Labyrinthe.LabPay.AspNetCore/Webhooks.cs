using System;
using System.Collections.Generic;
using System.IO;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Labyrinthe.LabPay.AspNetCore;

/// <summary>Handles the verified notifications of <c>MapLabPayWebhooks()</c>.</summary>
/// <remarks>Answer quickly (LabPay waits 10 s at most) and queue slow work. Deduplicate on <c>Payment.Id</c> (or the
/// <c>X-Labyrinthe-Delivery</c> header). Throwing gives a 500: LabPay delivers the notification again later.</remarks>
public interface ILabPayWebhookHandler
{
    /// <summary>Handles one verified notification.</summary>
    /// <param name="webhookEvent">The notification.</param>
    /// <param name="cancellationToken">Aborted when the request is aborted.</param>
    /// <returns>A task.</returns>
    Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken);
}

/// <summary>Options of the notification endpoint, bound to the <c>LabPay</c> section by <c>AddLabPay(configuration)</c>.</summary>
public sealed class LabPayWebhookOptions
{
    /// <summary>Signing secret (<c>whsec_…</c>, <c>GET /V2/account</c> with the live token); several secrets may be
    /// separated by commas. Defaults to <c>LABPAY_WEBHOOK_SECRET</c>.</summary>
    public string? WebhookSecret { get; set; }

    /// <summary>Max age of the signed timestamp (default 300 s); <see cref="TimeSpan.Zero"/> disables the check (not
    /// recommended).</summary>
    public TimeSpan WebhookTolerance { get; set; } = Webhook.DefaultTolerance;

    /// <summary>Largest accepted body, in bytes (default 1 MiB); larger requests get 413.</summary>
    public long WebhookMaxBodySize { get; set; } = 1024 * 1024;
}

/// <summary>Maps the notification endpoint (minimal API).</summary>
public static class LabPayEndpointRouteBuilderExtensions
{
    /// <summary>Default route of the notification endpoint, to use as <c>callback_url</c>.</summary>
    public const string DefaultPattern = "/webhooks/labpay";

    /// <summary>Maps <c>POST {pattern}</c>: reads the raw body, verifies the signature and calls the
    /// <see cref="ILabPayWebhookHandler"/> registered with <c>AddLabPayWebhookHandler&lt;T&gt;()</c>.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route, <c>/webhooks/labpay</c> by default.</param>
    /// <returns>The endpoint builder (add <c>RequireHost</c>, rate limiting…).</returns>
    public static RouteHandlerBuilder MapLabPayWebhooks(this IEndpointRouteBuilder endpoints, string pattern = DefaultPattern)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        return Map(endpoints, pattern, context => context.RequestServices.GetService<ILabPayWebhookHandler>()
            ?? throw new InvalidOperationException(
                "No ILabPayWebhookHandler is registered: call services.AddLabPayWebhookHandler<THandler>() or pass a handler to MapLabPayWebhooks."));
    }

    /// <summary>Maps <c>POST {pattern}</c> with a handler instance.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route.</param>
    /// <param name="handler">Called for each verified notification.</param>
    /// <returns>The endpoint builder.</returns>
    public static RouteHandlerBuilder MapLabPayWebhooks(this IEndpointRouteBuilder endpoints, string pattern, ILabPayWebhookHandler handler)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        ArgumentNullException.ThrowIfNull(handler);
        return Map(endpoints, pattern, _ => handler);
    }

    /// <summary>Maps <c>POST {pattern}</c> with a delegate.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route.</param>
    /// <param name="handler">Called for each verified notification.</param>
    /// <returns>The endpoint builder.</returns>
    public static RouteHandlerBuilder MapLabPayWebhooks(this IEndpointRouteBuilder endpoints, string pattern, Func<WebhookEvent, CancellationToken, Task> handler)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        ArgumentNullException.ThrowIfNull(handler);
        var wrapped = new DelegateHandler(handler);
        return Map(endpoints, pattern, _ => wrapped);
    }

    private static RouteHandlerBuilder Map(IEndpointRouteBuilder endpoints, string pattern, Func<HttpContext, ILabPayWebhookHandler> resolve)
    {
        ArgumentException.ThrowIfNullOrEmpty(pattern);
        return endpoints
            .MapPost(pattern, (Delegate)(Func<HttpContext, Task<IResult>>)(context => HandleAsync(context, resolve)))
            .WithName("LabPayWebhooks:" + pattern)
            .ExcludeFromDescription();
    }

    private static async Task<IResult> HandleAsync(HttpContext context, Func<HttpContext, ILabPayWebhookHandler> resolve)
    {
        var options = context.RequestServices.GetService<IOptions<LabPayWebhookOptions>>()?.Value ?? new LabPayWebhookOptions();
        var logger = context.RequestServices.GetService<ILoggerFactory>()?.CreateLogger("Labyrinthe.LabPay.AspNetCore.Webhooks");
        var secrets = Secrets(!string.IsNullOrEmpty(options.WebhookSecret) ? options.WebhookSecret : Environment.GetEnvironmentVariable("LABPAY_WEBHOOK_SECRET"));
        if (secrets.Count == 0)
        {
            throw new InvalidOperationException("The LabPay webhook secret is not configured: set LabPay:WebhookSecret or LABPAY_WEBHOOK_SECRET.");
        }

        var body = await ReadBodyAsync(context, options.WebhookMaxBodySize).ConfigureAwait(false);
        if (body is null)
        {
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);
        }

        WebhookEvent webhookEvent;
        try
        {
            webhookEvent = Webhook.ConstructEvent(body, context.Request.Headers[Webhook.SignatureHeader].ToString(), secrets, options.WebhookTolerance);
        }
        catch (Exception exception) when (exception is SignatureVerificationException or InvalidWebhookPayloadException)
        {
            var code = ((LabPayException)exception).Code;
            if (logger is not null)
            {
                Log.Rejected(logger, code, null);
            }

            return Results.Content("{\"received\":false,\"error\":\"" + code + "\"}", "application/json", null, StatusCodes.Status400BadRequest);
        }

        // A failing handler answers 500 itself (LabPay delivers the notification again later), without exposing the
        // exception: the developer exception page must never be sent to LabPay.
        try
        {
            await resolve(context).HandleAsync(webhookEvent, context.RequestAborted).ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is not OperationCanceledException || !context.RequestAborted.IsCancellationRequested)
        {
            if (logger is not null)
            {
                Log.HandlerFailed(logger, webhookEvent.Payment.Id, exception);
            }

            return Results.Content("{\"received\":false,\"error\":\"HANDLER_FAILED\"}", "application/json", null, StatusCodes.Status500InternalServerError);
        }

        return Results.Content("{\"received\":true}", "application/json");
    }

    private static List<string> Secrets(string? value)
    {
        var list = new List<string>();
        foreach (var part in (value ?? string.Empty).Split(','))
        {
            if (part.Trim().Length > 0)
            {
                list.Add(part.Trim());
            }
        }

        return list;
    }

    /// <summary>Corps brut, ou <see langword="null"/> s'il dépasse la taille maximale.</summary>
    private static async Task<byte[]?> ReadBodyAsync(HttpContext context, long maxBodySize)
    {
        if (context.Request.ContentLength > maxBodySize)
        {
            return null;
        }

        var sizeFeature = context.Features.Get<IHttpMaxRequestBodySizeFeature>();
        if (sizeFeature is { IsReadOnly: false })
        {
            sizeFeature.MaxRequestBodySize = maxBodySize;
        }

        using var buffer = new MemoryStream();
        var chunk = new byte[16 * 1024];
        int read;
        while ((read = await context.Request.Body.ReadAsync(chunk, context.RequestAborted).ConfigureAwait(false)) > 0)
        {
            if (buffer.Length + read > maxBodySize)
            {
                return null;
            }

            buffer.Write(chunk, 0, read);
        }

        return buffer.ToArray();
    }

    private sealed class DelegateHandler : ILabPayWebhookHandler
    {
        private readonly Func<WebhookEvent, CancellationToken, Task> handler;

        public DelegateHandler(Func<WebhookEvent, CancellationToken, Task> handler) => this.handler = handler;

        public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => handler(webhookEvent, cancellationToken);
    }

    private static class Log
    {
        public static readonly Action<ILogger, string, Exception?> Rejected =
            LoggerMessage.Define<string>(LogLevel.Warning, new EventId(10, "LabPayWebhookRejected"), "LabPay notification rejected: {Code}");

        public static readonly Action<ILogger, string, Exception?> HandlerFailed =
            LoggerMessage.Define<string>(LogLevel.Error, new EventId(11, "LabPayWebhookHandlerFailed"), "LabPay notification handler failed for payment {PaymentId}");
    }
}
