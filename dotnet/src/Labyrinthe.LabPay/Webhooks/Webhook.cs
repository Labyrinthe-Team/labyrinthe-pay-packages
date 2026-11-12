using System;
using System.Collections.Generic;
using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Labyrinthe.LabPay.Internal;

namespace Labyrinthe.LabPay;

/// <summary>A verified notification: <c>{"event", "payment", "sent_at"}</c>.</summary>
/// <remarks>The same notification can arrive more than once (redelivery): deduplicate on the
/// <c>X-Labyrinthe-Delivery</c> header or on <c>Payment.Id</c>. Arrival order is not guaranteed: when in doubt, read the
/// payment with <c>Payments.GetAsync</c>.</remarks>
public sealed record WebhookEvent
{
    /// <summary>The event name as sent (<c>payment.completed</c>, <c>payment.failed</c>, or a newer one).</summary>
    public string Name { get; init; } = string.Empty;

    /// <summary>The event as an enum (<see cref="WebhookEventType.Unknown"/> for a newer event).</summary>
    public WebhookEventType Type => ApiValues.ParseEventType(Name);

    /// <summary>The payment, same shape as in API responses.</summary>
    public Payment Payment { get; init; } = new();

    /// <summary>Sending date.</summary>
    public DateTimeOffset? SentAt { get; init; }

    /// <summary>Signed Unix timestamp (<c>t</c> of <c>X-Labyrinthe-Signature</c>).</summary>
    public long Timestamp { get; init; }

    /// <summary>The decoded body.</summary>
    public JsonElement Raw { get; init; }

    /// <summary>The payment reached <c>completed</c>: deliver the order (once).</summary>
    public bool IsPaymentCompleted => Type == WebhookEventType.PaymentCompleted;

    /// <summary>The payment reached <c>failed</c>.</summary>
    public bool IsPaymentFailed => Type == WebhookEventType.PaymentFailed;
}

/// <summary>Verification of the notifications sent to your <c>callback_url</c>.</summary>
/// <remarks>
/// <c>X-Labyrinthe-Signature: t=&lt;timestamp&gt;,v1=&lt;hex&gt;[,v1=&lt;hex&gt;]</c>: each <c>v1</c> is the
/// HMAC-SHA256 of <c>"&lt;timestamp&gt;.&lt;raw body&gt;"</c> keyed with your signing secret (<c>whsec_…</c>,
/// <c>GET /V2/account</c> with the live token). For 24 h after a rotation the header carries two <c>v1</c>; the
/// notification is accepted when either matches.
/// </remarks>
/// <example>
/// <code>
/// var webhookEvent = Webhook.ConstructEvent(rawBody, request.Headers[Webhook.SignatureHeader], secret);
/// if (webhookEvent.IsPaymentCompleted) { /* deliver webhookEvent.Payment.Reference once */ }
/// </code>
/// </example>
public static class Webhook
{
    /// <summary>Signature header.</summary>
    public const string SignatureHeader = "X-Labyrinthe-Signature";

    /// <summary>Event header.</summary>
    public const string EventHeader = "X-Labyrinthe-Event";

    /// <summary>Delivery id header (notification id).</summary>
    public const string DeliveryHeader = "X-Labyrinthe-Delivery";

    /// <summary>Mode header (<c>live</c> or <c>test</c>).</summary>
    public const string ModeHeader = "X-Labyrinthe-Mode";

    /// <summary>Default max age of the signed timestamp: 300 seconds.</summary>
    public static readonly TimeSpan DefaultTolerance = TimeSpan.FromSeconds(300);

    /// <summary>Verifies the signature and the timestamp, then returns the typed event.</summary>
    /// <param name="rawBody">The request body, byte for byte (never a re-encoded object).</param>
    /// <param name="signatureHeader">Value of <c>X-Labyrinthe-Signature</c> (<see langword="null"/> when missing).</param>
    /// <param name="secret">Your signing secret (<c>whsec_…</c>).</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 300 s); <see cref="TimeSpan.Zero"/> disables
    /// the check (not recommended).</param>
    /// <returns>The event.</returns>
    /// <exception cref="SignatureVerificationException">Wrong or missing signature, or old timestamp: answer 400.</exception>
    /// <exception cref="InvalidWebhookPayloadException">Signed body that is not a notification: answer 400.</exception>
    public static WebhookEvent ConstructEvent(byte[] rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null) =>
        ConstructEvent(rawBody, signatureHeader, new[] { secret }, tolerance, DateTimeOffset.UtcNow);

    /// <summary>Same as <c>ConstructEvent(byte[], …)</c> for a body read as UTF-8 text.</summary>
    /// <param name="rawBody">The request body as received.</param>
    /// <param name="signatureHeader">Value of <c>X-Labyrinthe-Signature</c>.</param>
    /// <param name="secret">Your signing secret.</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 300 s).</param>
    /// <returns>The event.</returns>
    public static WebhookEvent ConstructEvent(string rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        return ConstructEvent(Encoding.UTF8.GetBytes(rawBody), signatureHeader, new[] { secret }, tolerance, DateTimeOffset.UtcNow);
    }

    /// <summary>Verifies a notification with several accepted secrets (e.g. during your own rotation).</summary>
    /// <param name="rawBody">The request body, byte for byte.</param>
    /// <param name="signatureHeader">Value of <c>X-Labyrinthe-Signature</c>.</param>
    /// <param name="secrets">Accepted secrets (at least one non-empty).</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 300 s).</param>
    /// <returns>The event.</returns>
    public static WebhookEvent ConstructEvent(byte[] rawBody, string? signatureHeader, IEnumerable<string> secrets, TimeSpan? tolerance = null) =>
        ConstructEvent(rawBody, signatureHeader, secrets, tolerance, DateTimeOffset.UtcNow);

    /// <summary>Checks the signature (constant time) and the timestamp; returns the signed timestamp.</summary>
    /// <param name="rawBody">The request body, byte for byte.</param>
    /// <param name="signatureHeader">Value of <c>X-Labyrinthe-Signature</c>.</param>
    /// <param name="secret">Your signing secret.</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 300 s).</param>
    /// <returns>The signed Unix timestamp.</returns>
    /// <exception cref="SignatureVerificationException">Wrong or missing signature, or old timestamp.</exception>
    public static long Verify(byte[] rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null) =>
        Verify(rawBody, signatureHeader, new[] { secret }, tolerance, DateTimeOffset.UtcNow);

    /// <summary>True when the signature and the timestamp are valid.</summary>
    /// <param name="rawBody">The request body, byte for byte.</param>
    /// <param name="signatureHeader">Value of <c>X-Labyrinthe-Signature</c>.</param>
    /// <param name="secret">Your signing secret.</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 300 s).</param>
    /// <returns>Whether the notification is authentic and recent.</returns>
    public static bool IsValid(byte[] rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null)
    {
        try
        {
            Verify(rawBody, signatureHeader, secret, tolerance);
            return true;
        }
        catch (SignatureVerificationException)
        {
            return false;
        }
    }

    /// <summary>The <c>X-Labyrinthe-Signature</c> value LabPay would send now: to test your endpoint.</summary>
    /// <param name="rawBody">The body.</param>
    /// <param name="secret">The signing secret.</param>
    /// <param name="timestamp">Unix timestamp in seconds (default: now).</param>
    /// <returns>E.g. <c>t=1794391243,v1=5d41…</c>.</returns>
    public static string Sign(byte[] rawBody, string secret, long? timestamp = null) =>
        Sign(rawBody, new[] { secret }, timestamp ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds());

    /// <summary>Same as <c>Sign(byte[], …)</c> for a UTF-8 text body.</summary>
    /// <param name="rawBody">The body.</param>
    /// <param name="secret">The signing secret.</param>
    /// <param name="timestamp">Unix timestamp in seconds (default: now).</param>
    /// <returns>The header value.</returns>
    public static string Sign(string rawBody, string secret, long? timestamp = null)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        return Sign(Encoding.UTF8.GetBytes(rawBody), secret, timestamp);
    }

    /// <summary>The header LabPay would send with one <c>v1</c> per secret (as during a rotation).</summary>
    /// <param name="rawBody">The body.</param>
    /// <param name="secrets">The signing secrets.</param>
    /// <param name="timestamp">Unix timestamp in seconds.</param>
    /// <returns>E.g. <c>t=…,v1=…,v1=…</c>.</returns>
    public static string Sign(byte[] rawBody, IEnumerable<string> secrets, long timestamp)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        var header = new StringBuilder("t=").Append(timestamp.ToString(CultureInfo.InvariantCulture));
        foreach (var candidate in SecretList(secrets))
        {
            header.Append(",v1=").Append(ToHex(Hmac(candidate, timestamp, rawBody)));
        }

        return header.ToString();
    }

    internal static WebhookEvent ConstructEvent(byte[] rawBody, string? signatureHeader, IEnumerable<string> secrets, TimeSpan? tolerance, DateTimeOffset now)
    {
        var timestamp = Verify(rawBody, signatureHeader, secrets, tolerance, now);
        var decoded = Json.TryParse(rawBody);
        if (decoded is null)
        {
            throw new InvalidWebhookPayloadException("The notification body is not valid JSON.");
        }

        var body = decoded.Value;
        if (body.ValueKind != JsonValueKind.Object)
        {
            throw new InvalidWebhookPayloadException("The notification body is not a JSON object.");
        }

