using System;
using System.Security.Cryptography;
using System.Text;
using Xunit;

namespace Labyrinthe.LabPay.Tests;

public class WebhookTests
{
    internal const string Secret = "whsec_test_secret";
    private const long Now = 1_794_391_243L;

    internal static readonly string Body =
        "{\"event\":\"payment.completed\",\"payment\":" +
        Support.Fixtures.Payment(("status", "\"completed\""), ("gateway_status", "\"COMPLETED\""), ("completed_at", "\"2026-11-11T10:00:42+00:00\"")) +
        ",\"sent_at\":\"2026-11-11T10:00:43+00:00\"}";

    private static readonly byte[] Raw = Encoding.UTF8.GetBytes(Body);

    private static DateTimeOffset At(long seconds) => DateTimeOffset.FromUnixTimeSeconds(seconds);

    [Fact]
    public void Signs_like_the_engine()
    {
        var expected = "t=" + Now + ",v1=" + Convert.ToHexString(HMACSHA256.HashData(Encoding.UTF8.GetBytes(Secret), Encoding.UTF8.GetBytes(Now + "." + Body))).ToLowerInvariant();
        Assert.Equal(expected, Webhook.Sign(Raw, Secret, Now));
    }

    [Fact]
    public void Constructs_a_verified_event()
    {
        var webhookEvent = Webhook.ConstructEvent(Raw, Webhook.Sign(Raw, Secret, Now), new[] { Secret }, null, At(Now + 10));

        Assert.Equal("payment.completed", webhookEvent.Name);
        Assert.True(webhookEvent.IsPaymentCompleted);
        Assert.False(webhookEvent.IsPaymentFailed);
        Assert.Equal(Now, webhookEvent.Timestamp);
        Assert.Equal(new DateTimeOffset(2026, 11, 11, 10, 0, 43, TimeSpan.Zero), webhookEvent.SentAt);
        Assert.Equal(PaymentStatus.Completed, webhookEvent.Payment.Status);
        Assert.Equal("ORDER-42", webhookEvent.Payment.Reference);
    }

    [Fact]
    public void Accepts_either_signature_during_a_rotation()
    {
        var header = Webhook.Sign(Raw, new[] { "whsec_new", Secret }, Now);

        Assert.Equal(2, header.Split("v1=").Length - 1);
        Assert.Equal(Now, Webhook.Verify(Raw, header, new[] { Secret }, null, At(Now)));
        Assert.Equal(Now, Webhook.Verify(Raw, header, new[] { "whsec_new" }, null, At(Now)));
        Assert.Throws<SignatureVerificationException>(() => Webhook.Verify(Raw, header, new[] { "whsec_other" }, null, At(Now)));
        Assert.Equal(Now, Webhook.Verify(Raw, Webhook.Sign(Raw, Secret, Now), new[] { "whsec_new", Secret }, null, At(Now)));
    }

    [Fact]
    public void Refuses_a_tampered_body_an_old_timestamp_and_malformed_headers()
    {
        var header = Webhook.Sign(Raw, Secret, Now);
        var tampered = Encoding.UTF8.GetBytes(Body.Replace("1000.00", "9000.00", StringComparison.Ordinal));

        Assert.Throws<SignatureVerificationException>(() => Webhook.ConstructEvent(tampered, header, new[] { Secret }, null, At(Now)));
        var old = Assert.Throws<SignatureVerificationException>(() => Webhook.Verify(Raw, header, new[] { Secret }, null, At(Now + 301)));
        Assert.Contains("tolerance", old.Message, StringComparison.Ordinal);
        Assert.Equal(Now, Webhook.Verify(Raw, header, new[] { Secret }, TimeSpan.Zero, At(Now + 301)));
        foreach (var bad in new[] { string.Empty, "t=123", "v1=" + new string('a', 64), "t=123,v1=abc", null })
        {
            Assert.Throws<SignatureVerificationException>(() => Webhook.Verify(Raw, bad, new[] { Secret }, null, At(Now)));
        }
    }

    [Fact]
    public void Refuses_a_signed_body_that_is_not_a_notification()
    {
        var invalid = Encoding.UTF8.GetBytes("{\"event\":\"payment.completed\"}");
        var error = Assert.Throws<InvalidWebhookPayloadException>(() =>
            Webhook.ConstructEvent(invalid, Webhook.Sign(invalid, Secret, Now), new[] { Secret }, null, At(Now)));
        Assert.StartsWith("Invalid notification payload", error.Message, StringComparison.Ordinal);

        var notJson = Encoding.UTF8.GetBytes("nope");
        Assert.Throws<InvalidWebhookPayloadException>(() =>
            Webhook.ConstructEvent(notJson, Webhook.Sign(notJson, Secret, Now), new[] { Secret }, null, At(Now)));
    }

    [Fact]
    public void Refuses_an_empty_secret()
    {
        var error = Assert.Throws<InvalidArgumentException>(() => Webhook.Verify(Raw, "t=1,v1=" + new string('a', 64), string.Empty));
        Assert.Equal("INVALID_ARGUMENT", error.Code);
    }

    [Fact]
    public void Accepts_text_bodies_and_the_current_time()
    {
        Assert.Equal("ORDER-42", Webhook.ConstructEvent(Body, Webhook.Sign(Body, Secret), Secret).Payment.Reference);
        Assert.True(Webhook.IsValid(Raw, Webhook.Sign(Raw, Secret), Secret));
    }
}
