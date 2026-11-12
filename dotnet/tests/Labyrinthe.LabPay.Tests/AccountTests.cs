using System;
using System.Threading.Tasks;
using Labyrinthe.LabPay.Tests.Support;
using Xunit;

namespace Labyrinthe.LabPay.Tests;

public class AccountTests
{
    [Fact]
    public async Task Reads_the_account_and_balances_and_rotates_keys()
    {
        using var h = new Harness(o => o.ApiKey = "live_token_abcdefgh");
        h.Http
            .Data(200, "{\"mode\":\"live\",\"merchant\":\"Boutique\",\"live_enabled\":true,\"kyc_verified\":true,\"fees\":{\"deposit_rate\":\"0.0350\",\"payout_rate\":\"0.0350\"},\"test_key\":\"test_abc\",\"webhook_secret\":\"whsec_secret\"}")
            .Data(200, "[{\"currency\":\"CDF\",\"balance\":\"125000.00\",\"updated_at\":\"2026-11-11T10:00:42+00:00\"},{\"currency\":\"USD\",\"balance\":\"0.00\",\"updated_at\":null}]")
            .Data(200, "{\"webhook_secret\":\"whsec_new\",\"webhook_secret_previous_expires_at\":\"2026-11-12T10:00:00+00:00\"}")
            .Data(200, "{\"test_key\":\"test_new\"}");

        var account = await h.Client.Account.GetAsync();
        Assert.Equal(LabPayMode.Live, account.Mode);
        Assert.True(account.LiveEnabled);
        Assert.Equal("0.0350", account.DepositFeeRate);
        Assert.Equal("whsec_secret", account.WebhookSecret);
        Assert.Null(account.WebhookSecretPreviousExpiresAt);
        Assert.DoesNotContain("whsec_secret", account.ToString(), StringComparison.Ordinal);

        var cdf = await h.Client.Account.BalanceAsync("cdf");
        Assert.Equal(125000.00m, cdf!.Value);
        Assert.Equal("/api/V2/balances", h.Http.Last.Path);

        var secret = await h.Client.Account.RotateWebhookSecretAsync();
        Assert.Equal("whsec_new", secret.Secret);
        Assert.Equal("/api/V2/account/webhook-secret/rotate", h.Http.Last.Path);
        Assert.DoesNotContain("whsec_new", secret.ToString(), StringComparison.Ordinal);
        Assert.Equal("test_new", await h.Client.Account.RotateTestKeyAsync());
        Assert.Equal("/api/V2/account/test-key/rotate", h.Http.Last.Path);
    }

    [Fact]
    public async Task Reads_countries_providers_availability_and_predictions()
    {
        using var h = new Harness();
        h.Http
            .Data(200, "[{\"country\":\"COD\",\"name\":\"RD Congo\",\"dial_code\":\"243\",\"currencies\":[\"CDF\",\"USD\"],\"limits\":{\"CDF\":{\"min_amount\":\"100\",\"max_amount\":\"20000000\"},\"USD\":{\"min_amount\":null,\"max_amount\":\"10000\"}},\"default\":true}]")
            .Data(200, "[{\"provider\":\"VODACOM_MPESA_COD\",\"country\":\"COD\",\"name\":\"Vodacom M-Pesa\",\"name_displayed_to_customer\":\"M-PESA\",\"logo\":null,\"currencies\":{\"CDF\":{\"min_amount\":\"100\",\"max_amount\":\"2500000\",\"decimals\":\"NONE\",\"status\":\"OPERATIONAL\",\"pin_prompt\":\"AUTOMATIC\",\"pin_prompt_revivable\":false,\"pin_prompt_instructions\":null}}}]")
            .Data(200, "{\"VODACOM_MPESA_COD\":{\"country\":\"COD\",\"DEPOSIT\":\"OPERATIONAL\",\"PAYOUT\":\"DELAYED\"}}")
            .Data(200, "[]")
            .Data(200, "{\"phone\":\"243813456789\",\"provider\":\"VODACOM_MPESA_COD\",\"country\":\"COD\"}");

        var country = (await h.Client.Providers.CountriesAsync())[0];
        Assert.Equal("243", country.DialCode);
        Assert.True(country.IsDefault);
        Assert.Equal(new AmountLimits { MinAmount = null, MaxAmount = "10000" }, country.Limits["USD"]);

        var provider = (await h.Client.Providers.ListAsync(Operation.Payout, "cod"))[0];
        Assert.Equal("operation=payout&country=COD", h.Http.Last.Query);
        Assert.Equal("NONE", provider.Currencies["CDF"].AmountDecimals);
        Assert.False(provider.Currencies["CDF"].PinPromptRevivable!.Value.GetBoolean());
        Assert.Null(provider.Currencies["CDF"].PinPromptInstructions);

        var availability = await h.Client.Providers.AvailabilityAsync("COD");
        var mpesa = availability["VODACOM_MPESA_COD"];
        Assert.Equal("COD", mpesa.Country);
        Assert.Equal(2, mpesa.Statuses.Count);
        Assert.Equal("OPERATIONAL", mpesa.Statuses["DEPOSIT"]);
        Assert.Equal("DELAYED", mpesa.Statuses["PAYOUT"]);
        // PHP encode un objet vide en [].
        Assert.Empty(await h.Client.Providers.AvailabilityAsync());

        var prediction = await h.Client.Providers.PredictAsync("0813456789", "COD");
        Assert.Equal("{\"phone\":\"0813456789\",\"country\":\"COD\"}", h.Http.Last.Body);
        Assert.Equal("243813456789", prediction.Phone);
    }

    [Fact]
    public async Task Lists_and_resends_notifications()
    {
        const string Fields = "\"event\":\"payment.completed\",\"payment_id\":\"DEP-1\",\"url\":\"https://boutique.example/webhooks/labpay\"," +
            "\"last_status_code\":500,\"last_error\":\"HTTP 500\",\"next_attempt_at\":null,\"delivered_at\":null,\"created_at\":\"2026-11-11T10:00:43+00:00\",";
        using var h = new Harness();
        h.Http
            .Json(200, Fixtures.PageOf("{\"id\":12," + Fields + "\"status\":\"abandoned\",\"attempts\":6}", 1, 1, 20, 1))
            .Data(202, "{\"id\":13," + Fields + "\"status\":\"pending\",\"attempts\":0}");

        var page = await h.Client.Notifications.ListAsync(new ListNotificationsRequest { PaymentId = "DEP-1", Status = NotificationStatus.Abandoned });
        Assert.Equal("payment_id=DEP-1&status=abandoned", h.Http.Last.Query);
        Assert.False(page.HasMore);
        Assert.Equal(1, page.Total);
        var first = page.Data[0];
        Assert.Equal(12, first.Id);
        Assert.Equal(500, first.LastStatusCode);
        Assert.Equal(WebhookEventType.PaymentCompleted, first.EventType);

        var resent = await h.Client.Notifications.ResendAsync(12);
        Assert.Equal("/api/V2/notifications/12/resend", h.Http.Last.Path);
        Assert.Equal(13, resent.Id);
        Assert.Equal(NotificationStatus.Pending, resent.Status);
    }

    [Theory]
    [InlineData(null, "https://api.labyrinthe-rdc.com/api/V2")]
    [InlineData("http://localhost:8000", "http://localhost:8000/api/V2")]
    [InlineData("https://pay.example/api/V2/", "https://pay.example/api/V2")]
    public void Normalizes_the_base_url(string? baseUrl, string expected)
    {
        using var client = new LabPayClient(new LabPayClientOptions { ApiKey = "test_x", BaseUrl = baseUrl ?? LabPayClientOptions.DefaultBaseUrl });
        Assert.Equal(expected, client.BaseUrl);
    }

    [Fact]
    public void Refuses_an_invalid_base_url_and_a_missing_key()
    {
        Assert.Throws<InvalidArgumentException>(() => new LabPayClient(new LabPayClientOptions { ApiKey = "test_x", BaseUrl = "ftp://x" }));
        Assert.Throws<InvalidArgumentException>(() => new LabPayClient(new LabPayClientOptions { ApiKey = "test_x", MaxRetries = 42 }));
        if (Environment.GetEnvironmentVariable("LABPAY_API_KEY") is null)
        {
            Assert.Throws<InvalidArgumentException>(() => new LabPayClient(new LabPayClientOptions { ApiKey = " " }));
        }
    }

    [Fact]
    public void Knows_the_mode_of_the_key_and_never_prints_it()
    {
        using var live = new LabPayClient(new LabPayClientOptions { ApiKey = "live_secret_token_value" });
        using var test = new LabPayClient(new LabPayClientOptions { ApiKey = "test_x" });
        Assert.False(live.IsTestMode);
        Assert.True(test.IsTestMode);
        Assert.DoesNotContain("live_secret_token_value", live.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain("live_secret_token_value", new LabPayClientOptions { ApiKey = "live_secret_token_value" }.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public void The_version_matches_the_package()
    {
        var assembly = typeof(LabPayClient).Assembly.GetName().Version!;
        Assert.Equal(LabPayClient.Version, $"{assembly.Major}.{assembly.Minor}.{assembly.Build}");
    }
}
