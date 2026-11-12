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

        if (!body.TryGetProperty("event", out var name) || name.ValueKind != JsonValueKind.String)
        {
            throw new InvalidWebhookPayloadException("Invalid notification payload: \"event\" should be a string.");
        }

        var reader = Json.Webhook;
        return new WebhookEvent
        {
            Name = name.GetString() ?? string.Empty,
            Payment = Mapping.ToPayment(Json.Field(body, "payment"), reader),
            SentAt = Json.NullableDate(body, "sent_at"),
            Timestamp = timestamp,
            Raw = body,
        };
    }

    internal static long Verify(byte[] rawBody, string? signatureHeader, IEnumerable<string> secrets, TimeSpan? tolerance, DateTimeOffset now)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        var keys = SecretList(secrets);
        long? timestamp = null;
        var signatures = new List<byte[]>();
        foreach (var part in (signatureHeader ?? string.Empty).Split(','))
        {
            var index = part.IndexOf('=');
            if (index < 0)
            {
                continue;
            }

            var key = part.Substring(0, index).Trim();
            var value = part.Substring(index + 1).Trim();
            if (key == "t" && value.Length is > 0 and <= 12 && IsDigits(value))
            {
                timestamp = long.Parse(value, NumberStyles.None, CultureInfo.InvariantCulture);
            }
            else if (key == "v1" && value.Length == 64 && FromHex(value) is { } signature)
            {
                signatures.Add(signature);
            }
        }

        if (timestamp is not { } signedAt || signatures.Count == 0)
        {
            throw new SignatureVerificationException("Missing or malformed X-Labyrinthe-Signature header (expected \"t=<timestamp>,v1=<signature>\").");
        }

        var valid = false;
        foreach (var candidate in keys)
        {
            var expected = Hmac(candidate, signedAt, rawBody);
            foreach (var signature in signatures)
            {
                // Aucune sortie anticipée : chaque comparaison est en temps constant.
                valid = FixedTimeEquals(expected, signature) | valid;
            }
        }

        if (!valid)
        {
            throw new SignatureVerificationException("Invalid notification signature: the body was not signed with this secret.");
        }

        var maxAge = tolerance ?? DefaultTolerance;
        if (maxAge < TimeSpan.Zero)
        {
            throw new InvalidArgumentException("The tolerance cannot be negative.");
        }

        if (maxAge > TimeSpan.Zero && Math.Abs(now.ToUnixTimeSeconds() - signedAt) > (long)maxAge.TotalSeconds)
        {
            throw new SignatureVerificationException(
                $"Notification timestamp is outside the {(long)maxAge.TotalSeconds} s tolerance (possible replay).");
        }

        return signedAt;
    }

    private static List<string> SecretList(IEnumerable<string>? secrets)
    {
        var list = new List<string>();
        if (secrets is not null)
        {
            foreach (var secret in secrets)
            {
                if (!string.IsNullOrEmpty(secret))
                {
                    list.Add(secret);
                }
            }
        }

        if (list.Count == 0)
        {
            throw new InvalidArgumentException("The webhook signing secret cannot be empty (whsec_…, GET /V2/account with the live token).");
        }

        return list;
    }

    private static byte[] Hmac(string secret, long timestamp, byte[] body)
    {
        var prefix = Encoding.UTF8.GetBytes(timestamp.ToString(CultureInfo.InvariantCulture) + ".");
        var message = new byte[prefix.Length + body.Length];
        Buffer.BlockCopy(prefix, 0, message, 0, prefix.Length);
        Buffer.BlockCopy(body, 0, message, prefix.Length, body.Length);
        var key = Encoding.UTF8.GetBytes(secret);
#if NET8_0_OR_GREATER
        return HMACSHA256.HashData(key, message);
#else
        using var hmac = new HMACSHA256(key);
        return hmac.ComputeHash(message);
#endif
    }

    private static bool IsDigits(string value)
    {
        foreach (var c in value)
        {
            if (c is < '0' or > '9')
            {
                return false;
            }
        }

        return true;
    }

    private static int HexValue(char c) => c switch
    {
        >= '0' and <= '9' => c - '0',
        >= 'a' and <= 'f' => c - 'a' + 10,
        >= 'A' and <= 'F' => c - 'A' + 10,
        _ => -1,
    };

    private static byte[]? FromHex(string hex)
    {
        var bytes = new byte[hex.Length / 2];
        for (var i = 0; i < bytes.Length; i++)
        {
            var high = HexValue(hex[2 * i]);
            var low = HexValue(hex[(2 * i) + 1]);
            if (high < 0 || low < 0)
            {
                return null;
            }

            bytes[i] = (byte)((high << 4) | low);
        }

        return bytes;
    }

    private static string ToHex(byte[] bytes)
    {
        var builder = new StringBuilder(bytes.Length * 2);
        foreach (var b in bytes)
        {
            builder.Append(b.ToString("x2", CultureInfo.InvariantCulture));
        }

        return builder.ToString();
    }

#if NET8_0_OR_GREATER
    private static bool FixedTimeEquals(byte[] left, byte[] right) => CryptographicOperations.FixedTimeEquals(left, right);
#else
    [System.Runtime.CompilerServices.MethodImpl(System.Runtime.CompilerServices.MethodImplOptions.NoInlining | System.Runtime.CompilerServices.MethodImplOptions.NoOptimization)]
    private static bool FixedTimeEquals(byte[] left, byte[] right)
    {
        if (left.Length != right.Length)
        {
            return false;
        }

        var diff = 0;
        for (var i = 0; i < left.Length; i++)
        {
            diff |= left[i] ^ right[i];
        }

        return diff == 0;
    }
#endif
}
