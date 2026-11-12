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

