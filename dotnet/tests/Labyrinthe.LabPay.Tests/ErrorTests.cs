using System;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.LabPay.Tests.Support;
using Xunit;

namespace Labyrinthe.LabPay.Tests;

public class ErrorTests
{
    [Theory]
    [InlineData(401, "UNAUTHENTICATED", typeof(AuthenticationException))]
    [InlineData(403, "KYC_REQUIRED", typeof(PermissionException))]
    [InlineData(403, "LIVE_TOKEN_REQUIRED", typeof(PermissionException))]
    [InlineData(404, "PAYMENT_NOT_FOUND", typeof(NotFoundException))]
    [InlineData(409, "REFUND_IN_PROGRESS", typeof(ConflictException))]
    [InlineData(422, "INVALID_PHONE_NUMBER", typeof(InvalidPhoneNumberException))]
    [InlineData(422, "INVALID_CURRENCY", typeof(InvalidRequestException))]
    [InlineData(422, "AMOUNT_TOO_SMALL", typeof(AmountOutOfRangeException))]
    [InlineData(422, "INSUFFICIENT_BALANCE", typeof(InsufficientBalanceException))]
    [InlineData(429, "DAILY_LIMIT_REACHED", typeof(DailyLimitReachedException))]
    [InlineData(503, "MAINTENANCE", typeof(ServiceUnavailableException))]
    [InlineData(418, "SOMETHING_NEW", typeof(ApiException))]
    public async Task Maps_errors_by_code_then_status(int status, string code, Type type)
    {
        using var h = new Harness(o => o.MaxRetries = 0);
        h.Http.Error(status, code);

        var error = await Assert.ThrowsAnyAsync<ApiException>(() => h.Client.Payments.GetAsync("DEP-1"));

        Assert.Equal(type, error.GetType());
        Assert.Equal(code, error.Code);
        Assert.Equal(status, error.StatusCode);
        Assert.Equal("Message en français", error.Message);
        Assert.Equal("test", error.Mode);
    }

    [Fact]
    public async Task Exposes_validation_errors()
    {
        using var h = new Harness();
        h.Http.Json(422, "{\"message\":\"Montant invalide\",\"code\":\"VALIDATION_ERROR\",\"errors\":{\"amount\":[\"Montant invalide\"]}}");

        var error = await Assert.ThrowsAsync<ValidationException>(() =>
            h.Client.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "CDF", Phone = "0813456789" }));

        Assert.Equal(new[] { "Montant invalide" }, error.Errors["amount"]);
    }

    [Fact]
    public async Task Maps_a_422_without_code_but_with_errors_to_a_validation_error()
    {
        using var h = new Harness();
        h.Http.Json(422, "{\"message\":\"The selected status is invalid.\",\"errors\":{\"status\":[\"The selected status is invalid.\"]}}");

        var error = await Assert.ThrowsAsync<ValidationException>(() => h.Client.Notifications.ListAsync());
        Assert.Equal("VALIDATION_ERROR", error.Code);
    }

    [Fact]
    public async Task Rejects_a_body_that_is_not_json()
    {
        using var h = new Harness();
        h.Http.Raw(200, "not json");

        await Assert.ThrowsAsync<UnexpectedResponseException>(() => h.Client.Account.GetAsync());
    }

    [Fact]
    public async Task Retries_the_rate_limiter_after_retry_after()
    {
        using var h = new Harness();
        h.Http.Json(429, "{\"message\":\"Too Many Attempts.\"}", ("Retry-After", "3")).Data(200, Fixtures.Payment());

        await h.Client.Payments.GetAsync("DEP-1");

        Assert.Equal(2, h.Requests.Count);
        Assert.Equal(new[] { TimeSpan.FromSeconds(3) }, h.Sleeps);
    }

    [Fact]
    public async Task Throws_when_retry_after_is_longer_than_max_retry_wait()
    {
        using var h = new Harness();
        h.Http.Json(429, "{\"message\":\"Too Many Attempts.\"}", ("Retry-After", "60"));

        var error = await Assert.ThrowsAsync<RateLimitException>(() => h.Client.Payments.GetAsync("DEP-1"));

        Assert.Equal("TOO_MANY_REQUESTS", error.Code);
        Assert.Equal(60, error.RetryAfter);
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Never_retries_the_daily_limit()
    {
        using var h = new Harness();
        h.Http.Error(429, "DAILY_LIMIT_REACHED", ("Retry-After", "1"));

        await Assert.ThrowsAsync<DailyLimitReachedException>(() =>
            h.Client.Deposits.CreateAsync(new MobileMoneyRequest { Amount = 10m, Currency = "CDF", Phone = "0813456789" }));
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Retries_server_errors_on_reads_with_backoff()
    {
        using var h = new Harness();
        h.Http.Error(503, "GATEWAY_UNAVAILABLE").Raw(502, "<html>").Data(200, "[]");

        Assert.Empty(await h.Client.Providers.ListAsync());
        Assert.Equal(3, h.Requests.Count);
        // Gigue fixée à 1 : 0,5 s puis 1 s.
        Assert.Equal(new[] { TimeSpan.FromMilliseconds(500), TimeSpan.FromSeconds(1) }, h.Sleeps);
    }

    [Fact]
    public async Task Only_retries_maintenance_with_a_short_retry_after()
    {
        using var h = new Harness();
        h.Http.Error(503, "MAINTENANCE", ("Retry-After", "2")).Error(503, "MAINTENANCE");

        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Payments.GetAsync("DEP-1"));
        Assert.Equal(2, h.Requests.Count);
        Assert.Equal(new[] { TimeSpan.FromSeconds(2) }, h.Sleeps);
    }

    [Fact]
    public async Task Never_retries_rotations_after_a_server_error()
    {
        using var h = new Harness(o => o.ApiKey = "live_token_abcdefgh");
        h.Http.Raw(500, string.Empty);

        await Assert.ThrowsAsync<ServiceUnavailableException>(() => h.Client.Account.RotateWebhookSecretAsync());
        Assert.Single(h.Requests);
    }

    [Fact]
    public async Task Retries_reads_after_a_network_error()
    {
        using var h = new Harness();
        h.Http.NetworkError().Data(200, Fixtures.Payment());

        Assert.Equal("ORDER-42", (await h.Client.Payments.GetAsync("DEP-1")).Reference);
        Assert.Equal(2, h.Requests.Count);
    }

    [Fact]
    public async Task Reports_a_timeout_without_leaking_the_query_string()
    {
        using var h = new Harness(o =>
        {
            o.Timeout = TimeSpan.FromMilliseconds(50);
            o.MaxRetries = 0;
        });
        h.Http.Hang();

        var error = await Assert.ThrowsAsync<ConnectionException>(() =>
            h.Client.Payments.ListAsync(new ListPaymentsRequest { Reference = "ORDER-1" }));

        Assert.Equal("TIMEOUT", error.Code);
        Assert.True(error.IsTimeout);
        Assert.DoesNotContain("ORDER-1", error.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Honours_the_cancellation_token()
    {
        using var h = new Harness();
        using var cancellation = new CancellationTokenSource();
        cancellation.Cancel();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => h.Client.Payments.GetAsync("DEP-1", cancellation.Token));
        Assert.Empty(h.Requests);
    }
}
