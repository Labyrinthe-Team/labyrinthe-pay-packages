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

