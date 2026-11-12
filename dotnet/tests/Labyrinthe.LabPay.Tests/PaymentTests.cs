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
        using var h = new Harness();
        h.Http.Data(422, Fixtures.Payment(("status", "\"failed\""), ("failure", "{\"code\":\"PAYER_LIMIT_REACHED\",\"message\":\"Limite\"}")));

        var payment = await h.Client.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 1000m, Currency = "CDF", Phone = "243813456019" });

        Assert.Equal(PaymentStatus.Failed, payment.Status);
        Assert.Equal(new PaymentFailure { Code = "PAYER_LIMIT_REACHED", Message = "Limite" }, payment.Failure);
        Assert.Equal(422, h.Client.LastResponse!.Status);
    }

    [Fact]
    public async Task Creates_a_refund()
    {
        using var h = new Harness();
        h.Http.Data(201, Fixtures.Payment(("id", "\"RFD-1\""), ("operation", "\"refund\""), ("refunded_payment_id", "\"DEP-1\"")));

        var refund = await h.Client.Refunds.CreateAsync(new RefundRequest { PaymentId = "DEP-1", Amount = 300m, Reference = "REFUND-42-1" });

        Assert.Equal("{\"payment_id\":\"DEP-1\",\"amount\":\"300\",\"reference\":\"REFUND-42-1\"}", h.Http.Last.Body);
        Assert.Equal("DEP-1", refund.RefundedPaymentId);
        Assert.Equal(Operation.Refund, refund.Operation);
    }

    [Fact]
    public async Task Gets_syncs_and_cancels()
    {
        using var h = new Harness();
        h.Http
            .Data(200, Fixtures.Payment(("id", "\"PAY-1\""), ("operation", "\"payout\""), ("gateway_status", "\"ENQUEUED\"")))
            .Data(200, Fixtures.Payment(("id", "\"PAY-1\""), ("operation", "\"payout\"")))
            .Data(200, Fixtures.Payment(("status", "\"completed\""), ("completed_at", "\"2026-11-11T10:00:42+00:00\"")));

        Assert.True((await h.Client.Payments.GetAsync("PAY-1")).IsQueued);
        Assert.Equal("GET /api/V2/payments/PAY-1", h.Http.Last.Method + " " + h.Http.Last.Path);
        await h.Client.Payouts.CancelAsync("PAY-1");
        Assert.Equal("POST /api/V2/payouts/PAY-1/cancel", h.Http.Last.Method + " " + h.Http.Last.Path);
        var synced = await h.Client.Payments.SyncAsync("DEP-1");
        Assert.Equal("POST /api/V2/payments/DEP-1/sync", h.Http.Last.Method + " " + h.Http.Last.Path);
        Assert.True(synced.IsCompleted);
        Assert.Equal(new DateTimeOffset(2026, 11, 11, 10, 0, 42, TimeSpan.Zero), synced.CompletedAt);
    }

    [Fact]
    public async Task Lists_and_iterates_over_pages()
    {
        using var h = new Harness();
        h.Http
            .Json(200, Fixtures.PageOf(Fixtures.Payment(), 1, 2, 1, 2))
            .Json(200, Fixtures.PageOf(Fixtures.Payment(("id", "\"DEP-2\"")), 2, 2, 1, 2));

        var ids = new List<string>();
        await foreach (var payment in h.Client.Payments.IterateAsync(new ListPaymentsRequest
        {
            Status = PaymentStatus.Completed,
            Operation = Operation.Deposit,
            From = new DateTime(2026, 11, 1),
            PerPage = 1,
        }))
        {
            ids.Add(payment.Id);
        }

        Assert.Equal(new[] { "DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6", "DEP-2" }, ids);
        Assert.Equal("status=completed&operation=deposit&from=2026-11-01&per_page=1&page=2", h.Http.Last.Query);
    }

    [Fact]
    public async Task Finds_by_reference()
    {
        using var h = new Harness();
        h.Http.Json(200, Fixtures.PageOf(string.Empty, 1, 1, 1, 0));

        Assert.Null(await h.Client.Payments.FindByReferenceAsync("ORDER-404"));
        Assert.Equal("reference=ORDER-404&per_page=1", h.Http.Last.Query);
    }

    public static IEnumerable<object[]> InvalidCalls()
    {
        yield return Case("zero amount", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 0m, Currency = "CDF", Phone = "0813456789" }));
        yield return Case("three decimals", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 1.234m, Currency = "CDF", Phone = "0813456789" }));
        yield return Case("bad currency", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "FC", Phone = "0813456789" }));
        yield return Case("bad reference", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "CDF", Phone = "0813456789", Reference = "order 42" }));
        yield return Case("http callback", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "CDF", Phone = "0813456789", CallbackUrl = "http://example.test" }));
        yield return Case("short message", c => c.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "CDF", Phone = "0813456789", CustomerMessage = "Hi" }));
        yield return Case("per page", c => c.Payments.ListAsync(new ListPaymentsRequest { PerPage = 101 }));
        yield return Case("dates", c => c.Payments.ListAsync(new ListPaymentsRequest { From = new DateTime(2026, 11, 2), To = new DateTime(2026, 11, 1) }));
        yield return Case("unknown status", c => c.Payments.ListAsync(new ListPaymentsRequest { Status = PaymentStatus.Unknown }));
        yield return Case("notification id", c => c.Notifications.ResendAsync(0));
    }

    [Theory]
    [MemberData(nameof(InvalidCalls))]
    public async Task Rejects_invalid_input_before_any_request(string name, Func<LabPayClient, Task> call)
    {
        using var h = new Harness();

        var error = await Assert.ThrowsAsync<InvalidArgumentException>(() => call(h.Client));

        Assert.Equal("INVALID_ARGUMENT", error.Code);
        Assert.True(h.Requests.Count == 0, name);
    }

    private static object[] Case(string name, Func<LabPayClient, Task> call) => new object[] { name, call };
}
