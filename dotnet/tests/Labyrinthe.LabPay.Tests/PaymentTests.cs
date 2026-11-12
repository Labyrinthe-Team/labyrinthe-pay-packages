using System;
using System.Collections.Generic;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using Labyrinthe.LabPay.Tests.Support;
using Xunit;

namespace Labyrinthe.LabPay.Tests;

public class PaymentTests
{
    [Fact]
    public async Task Sends_the_documented_body_and_headers()
    {
        using var h = new Harness();
        h.Http.Data(201, Fixtures.Payment());

        var payment = await h.Client.Deposits.CreateAsync(new MobileMoneyRequest
        {
            Amount = 1000m,
            Currency = "cdf",
            Phone = "0813456789",
            Reference = "ORDER-42",
            CallbackUrl = "https://boutique.example/webhooks/labpay",
            CustomerMessage = "Commande 42",
            Metadata = new Dictionary<string, object?> { ["orderId"] = 42 },
        });

        var request = h.Http.Last;
        Assert.Equal("POST", request.Method.Method);
        Assert.Equal("https://api.example.test/api/V2/deposits", request.Uri.ToString());
        Assert.Equal("Bearer " + Harness.Key, request.Header("Authorization"));
        Assert.StartsWith("application/json", request.Header("Content-Type"), StringComparison.Ordinal);
        Assert.StartsWith("labpay-dotnet/" + LabPayClient.Version, request.Header("User-Agent"), StringComparison.Ordinal);
        Assert.Equal(
            "{\"amount\":\"1000\",\"currency\":\"CDF\",\"phone\":\"0813456789\",\"callback_url\":\"https://boutique.example/webhooks/labpay\"," +
            "\"customer_message\":\"Commande 42\",\"metadata\":{\"orderId\":\"42\"},\"reference\":\"ORDER-42\"}",
            request.Body);
        Assert.Equal("DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6", payment.Id);
        Assert.Equal("1035.00", payment.TotalAmount);
        Assert.Equal(1000.00m, payment.AmountValue);
        Assert.Equal(Operation.Deposit, payment.Operation);
        Assert.Equal(PaymentStatus.Pending, payment.Status);
        Assert.False(payment.Livemode);
        Assert.False(payment.IsFinal);
        Assert.Equal("42", payment.Metadata!["orderId"]);
        Assert.Equal(new DateTimeOffset(2026, 11, 11, 10, 0, 0, TimeSpan.Zero), payment.CreatedAt);
        Assert.Equal(LabPayMode.Test, h.Client.LastResponse!.Mode);
        Assert.DoesNotContain("0813456789", payment.ToString(), StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("12.5", "12.50")]
    [InlineData("1500.00", "1500")]
    [InlineData("0.01", "0.01")]
    public async Task Formats_exact_decimal_amounts(string amount, string sent)
    {
        using var h = new Harness();
        h.Http.Data(201, Fixtures.Payment());

        await h.Client.Payouts.CreateAsync(new MobileMoneyRequest
        {
            Amount = decimal.Parse(amount, System.Globalization.CultureInfo.InvariantCulture),
            Currency = "USD",
            Phone = "0813456789",
            Reference = "A",
        });

        Assert.Equal(sent, h.Http.Last.Json.GetProperty("amount").GetString());
    }

    [Fact]
    public async Task Generates_a_reference_when_missing_and_keeps_it_across_retries()
    {
        using var h = new Harness();
        h.Http.Raw(500, string.Empty).Data(201, Fixtures.Payment());

        await h.Client.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 12.50m, Currency = "USD", Phone = "+243813456789" });

        Assert.Equal(2, h.Requests.Count);
        var reference = h.Requests[0].Json.GetProperty("reference").GetString()!;
        Assert.Matches(new Regex("^sdk_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"), reference);
        Assert.Equal(h.Requests[0].Body, h.Requests[1].Body);
        Assert.Single(h.Sleeps);
    }

    [Fact]
    public async Task Does_not_retry_a_payment_without_reference_after_a_network_error()
    {
        using var h = new Harness(o => o.AutoReference = false);
        h.Http.NetworkError();

        var error = await Assert.ThrowsAsync<ConnectionException>(() =>
            h.Client.Payouts.CreateAsync(new MobileMoneyRequest { Amount = 5000m, Currency = "CDF", Phone = "0813456789" }));

        Assert.Equal("CONNECTION_ERROR", error.Code);
        Assert.Contains("not retried automatically", error.Message, StringComparison.Ordinal);
        Assert.Single(h.Requests);
        Assert.Equal("/api/V2/payouts", h.Requests[0].Path);
        Assert.False(h.Requests[0].Json.TryGetProperty("reference", out _));
    }

    [Fact]
    public async Task Returns_a_payment_refused_at_initiation_instead_of_throwing()
    {
