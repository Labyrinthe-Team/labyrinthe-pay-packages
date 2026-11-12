using System;
using System.Net.Http;
using System.Threading;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Labyrinthe.LabPay.AspNetCore;

/// <summary>Registers <see cref="LabPayClient"/> in the dependency injection container.</summary>
public static class LabPayServiceCollectionExtensions
{
    /// <summary>Default configuration section: <c>LabPay</c>.</summary>
    public const string DefaultSectionName = "LabPay";

    /// <summary>Name of the <see cref="HttpClient"/> used by <see cref="LabPayClient"/> (<c>IHttpClientFactory</c>).</summary>
    public const string HttpClientName = "Labyrinthe.LabPay";

    /// <summary>Registers <see cref="LabPayClient"/> (typed client of <c>IHttpClientFactory</c>) with options bound to a
    /// configuration section.</summary>
    /// <remarks>
    /// <code>
    /// // appsettings.json: { "LabPay": { "ApiKey": "test_…", "WebhookSecret": "whsec_…" } }
    /// builder.Services.AddLabPay(builder.Configuration);
    /// </code>
    /// The section binds <see cref="LabPayClientOptions"/> (<c>ApiKey</c>, <c>BaseUrl</c>, <c>Timeout</c>,
    /// <c>MaxRetries</c>, <c>MaxRetryWait</c>, <c>AutoReference</c>) and <see cref="LabPayWebhookOptions"/>
    /// (<c>WebhookSecret</c>, <c>WebhookTolerance</c>, <c>WebhookMaxBodySize</c>). Without <c>ApiKey</c>, the client
    /// reads <c>LABPAY_API_KEY</c>. SDK logs go to <c>ILogger&lt;LabPayClient&gt;</c>.
    /// </remarks>
    /// <param name="services">The services.</param>
    /// <param name="configuration">The application configuration (the root, or any parent of the section).</param>
    /// <param name="sectionName">Section name, <c>LabPay</c> by default.</param>
    /// <returns>The HTTP client builder, to add handlers (proxy…). Do not add automatic retries: the SDK retries only
    /// when it is safe (no double payment).</returns>
    public static IHttpClientBuilder AddLabPay(this IServiceCollection services, IConfiguration configuration, string sectionName = DefaultSectionName)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configuration);
        ArgumentException.ThrowIfNullOrEmpty(sectionName);

        var section = configuration.GetSection(sectionName);
        services.AddOptions<LabPayClientOptions>().Bind(section);
        services.AddOptions<LabPayWebhookOptions>().Bind(section);
        return AddClient(services);
    }

    /// <summary>Registers <see cref="LabPayClient"/> with options set in code.</summary>
    /// <param name="services">The services.</param>
    /// <param name="configure">Sets the client options.</param>
    /// <returns>The HTTP client builder.</returns>
    public static IHttpClientBuilder AddLabPay(this IServiceCollection services, Action<LabPayClientOptions> configure)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configure);

        services.AddOptions<LabPayClientOptions>().Configure(configure);
        services.AddOptions<LabPayWebhookOptions>();
        return AddClient(services);
    }

    /// <summary>Registers the handler called by <c>MapLabPayWebhooks()</c> (scoped).</summary>
    /// <typeparam name="THandler">Your handler.</typeparam>
    /// <param name="services">The services.</param>
    /// <returns>The services.</returns>
    public static IServiceCollection AddLabPayWebhookHandler<THandler>(this IServiceCollection services)
        where THandler : class, ILabPayWebhookHandler
    {
        ArgumentNullException.ThrowIfNull(services);
        services.TryAddScoped<ILabPayWebhookHandler, THandler>();
        return services;
    }

    private static IHttpClientBuilder AddClient(IServiceCollection services)
    {
        var builder = services.AddHttpClient(HttpClientName);
        builder.AddTypedClient<LabPayClient>((http, provider) =>
        {
            var options = provider.GetRequiredService<IOptions<LabPayClientOptions>>().Value.Clone();
            options.Logger ??= new MicrosoftLoggerAdapter(provider.GetRequiredService<ILoggerFactory>().CreateLogger<LabPayClient>());
            options.UserAgentSuffix ??= "labpay-aspnetcore/" + LabPayClient.Version;
            return new LabPayClient(options, http);
        });
        return builder
            // Le SDK gère le délai de chaque tentative ; aucune redirection n'est suivie.
            .ConfigureHttpClient(http => http.Timeout = Timeout.InfiniteTimeSpan)
            .ConfigurePrimaryHttpMessageHandler(() => new SocketsHttpHandler
            {
                AllowAutoRedirect = false,
                AutomaticDecompression = System.Net.DecompressionMethods.GZip | System.Net.DecompressionMethods.Deflate,
            });
    }
}
