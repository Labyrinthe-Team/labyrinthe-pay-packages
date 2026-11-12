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
