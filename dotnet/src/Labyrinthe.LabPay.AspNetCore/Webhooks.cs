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

