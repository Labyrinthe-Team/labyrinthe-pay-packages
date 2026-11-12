using System;
using System.Collections.Generic;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.LabPay.AspNetCore;
using Labyrinthe.LabPay.Tests.Support;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Xunit;

namespace Labyrinthe.LabPay.Tests;

public class AspNetCoreTests
{
    private const string Secret = WebhookTests.Secret;

    [Fact]
    public async Task AddLabPay_binds_the_configuration_section_and_uses_the_http_client_factory()
    {
        var mock = new MockHttp().Data(200, "[]");
        var services = new ServiceCollection();
        services.AddLogging();
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["LabPay:ApiKey"] = "test_from_config",
            ["LabPay:BaseUrl"] = "http://localhost:8000",
            ["LabPay:Timeout"] = "00:00:05",
            ["LabPay:MaxRetries"] = "4",
            ["LabPay:WebhookSecret"] = Secret,
        }).Build();

        services.AddLabPay(configuration).ConfigurePrimaryHttpMessageHandler(() => mock);
        using var provider = services.BuildServiceProvider();

        var options = provider.GetRequiredService<IOptions<LabPayClientOptions>>().Value;
        Assert.Equal(TimeSpan.FromSeconds(5), options.Timeout);
        Assert.Equal(4, options.MaxRetries);
        Assert.Equal(Secret, provider.GetRequiredService<IOptions<LabPayWebhookOptions>>().Value.WebhookSecret);

        var client = provider.GetRequiredService<LabPayClient>();
        Assert.True(client.IsTestMode);
        Assert.Equal("http://localhost:8000/api/V2", client.BaseUrl);
        Assert.Empty(await client.Account.BalancesAsync());
        Assert.Equal("Bearer test_from_config", mock.Requests[0].Header("Authorization"));
        Assert.Contains("labpay-aspnetcore/", mock.Requests[0].Header("User-Agent"), StringComparison.Ordinal);
    }

    [Fact]
    public async Task Webhook_endpoint_accepts_a_signed_notification_and_calls_the_handler()
    {
        var handler = new RecordingHandler();
        await using var app = await StartAsync(services => services.AddSingleton<ILabPayWebhookHandler>(handler), app => app.MapLabPayWebhooks());
        using var http = app.GetTestClient();

        using var response = await PostAsync(http, "/webhooks/labpay", WebhookTests.Body, Webhook.Sign(WebhookTests.Body, Secret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("{\"received\":true}", await response.Content.ReadAsStringAsync());
        var received = Assert.Single(handler.Events);
        Assert.True(received.IsPaymentCompleted);
        Assert.Equal("ORDER-42", received.Payment.Reference);
    }

    [Fact]
    public async Task Webhook_endpoint_rejects_bad_signatures_without_calling_the_handler()
    {
        var handler = new RecordingHandler();
        await using var app = await StartAsync(_ => { }, app => app.MapLabPayWebhooks("/hooks", handler));
        using var http = app.GetTestClient();
        var body = WebhookTests.Body;
        var old = DateTimeOffset.UtcNow.AddHours(-1).ToUnixTimeSeconds();

        using var wrong = await PostAsync(http, "/hooks", body, Webhook.Sign(body, "another-secret"));
        using var missing = await PostAsync(http, "/hooks", body, null);
        using var expired = await PostAsync(http, "/hooks", body, Webhook.Sign(body, Secret, old));
        using var notJson = await PostAsync(http, "/hooks", "nope", Webhook.Sign("nope", Secret));
        using var get = await http.GetAsync(new Uri("/hooks", UriKind.Relative));

        Assert.Equal(HttpStatusCode.BadRequest, wrong.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, missing.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, expired.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, notJson.StatusCode);
        Assert.Contains("SIGNATURE_VERIFICATION_FAILED", await wrong.Content.ReadAsStringAsync(), StringComparison.Ordinal);
        Assert.Contains("INVALID_WEBHOOK_PAYLOAD", await notJson.Content.ReadAsStringAsync(), StringComparison.Ordinal);
        Assert.Equal(HttpStatusCode.MethodNotAllowed, get.StatusCode);
        Assert.Empty(handler.Events);
    }

    [Fact]
    public async Task Webhook_endpoint_accepts_several_secrets_and_a_delegate()
    {
        var events = new List<WebhookEvent>();
        await using var app = await StartAsync(
            _ => { },
            app => app.MapLabPayWebhooks("/wh", (evt, _) =>
            {
                events.Add(evt);
                return Task.CompletedTask;
            }),
            secret: "whsec_other," + Secret);
        using var http = app.GetTestClient();

        using var response = await PostAsync(http, "/wh", WebhookTests.Body, Webhook.Sign(WebhookTests.Body, Secret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Single(events);
    }

    [Fact]
    public async Task Webhook_endpoint_refuses_large_bodies_and_reports_handler_failures()
    {
        await using var app = await StartAsync(
            services => services.Configure<LabPayWebhookOptions>(o => o.WebhookMaxBodySize = 64),
            app => app.MapLabPayWebhooks("/wh", (_, _) => throw new InvalidOperationException("database down")));
        using var http = app.GetTestClient();
        using var tooLarge = await PostAsync(http, "/wh", WebhookTests.Body, Webhook.Sign(WebhookTests.Body, Secret));
        Assert.Equal(HttpStatusCode.RequestEntityTooLarge, tooLarge.StatusCode);

        await using var app2 = await StartAsync(_ => { }, a => a.MapLabPayWebhooks("/wh", (_, _) => throw new InvalidOperationException("database down")));
        using var http2 = app2.GetTestClient();
        using var failed = await PostAsync(http2, "/wh", WebhookTests.Body, Webhook.Sign(WebhookTests.Body, Secret));
        Assert.Equal(HttpStatusCode.InternalServerError, failed.StatusCode);
    }

    [Fact]
    public void AddLabPayWebhookHandler_registers_a_scoped_handler()
    {
        var services = new ServiceCollection();
        services.AddLabPayWebhookHandler<RecordingHandler>();
        var descriptor = Assert.Single(services, d => d.ServiceType == typeof(ILabPayWebhookHandler));
        Assert.Equal(ServiceLifetime.Scoped, descriptor.Lifetime);
        Assert.Equal(typeof(RecordingHandler), descriptor.ImplementationType);
    }

    private static async Task<WebApplication> StartAsync(Action<IServiceCollection> services, Action<WebApplication> map, string? secret = Secret)
    {
        var builder = WebApplication.CreateSlimBuilder();
        builder.WebHost.UseTestServer();
        builder.Logging.ClearProviders();
        builder.Configuration.AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["LabPay:ApiKey"] = "test_x",
            ["LabPay:WebhookSecret"] = secret,
        });
        builder.Services.AddLabPay(builder.Configuration);
        services(builder.Services);
        var app = builder.Build();
        map(app);
        await app.StartAsync();
        return app;
    }

    private static async Task<HttpResponseMessage> PostAsync(HttpClient http, string path, string body, string? signature)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(path, UriKind.Relative))
        {
            Content = new StringContent(body, Encoding.UTF8, "application/json"),
        };
        if (signature is not null)
        {
            request.Headers.TryAddWithoutValidation(Webhook.SignatureHeader, signature);
        }

        return await http.SendAsync(request);
    }

    private sealed class RecordingHandler : ILabPayWebhookHandler
    {
        public List<WebhookEvent> Events { get; } = new();

        public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken)
        {
            Events.Add(webhookEvent);
            return Task.CompletedTask;
        }
    }
}
