using System;
using System.Collections.Generic;

namespace Labyrinthe.LabPay;

/// <summary>A deposit (<c>POST /V2/deposits</c>) or a payout (<c>POST /V2/payouts</c>). Checked by the SDK before any
/// request.</summary>
/// <example>
/// <code>
/// new MobileMoneyRequest
/// {
///     Amount = 1000m,
///     Currency = "CDF",
///     Phone = "0813456789",
///     Reference = "ORDER-42",
///     CallbackUrl = "https://shop.example/webhooks/labpay",
/// };
/// </code>
/// </example>
public sealed class MobileMoneyRequest
{
    /// <summary>Net amount, greater than 0, at most 2 decimals (<c>1500m</c>, <c>12.50m</c>): credited to you for a
    /// deposit (the customer pays it plus fees), received by the recipient for a payout.</summary>
    public decimal Amount { get; init; }

    /// <summary><c>CDF</c> or <c>USD</c>.</summary>
    public string Currency { get; init; } = string.Empty;

    /// <summary>Local (<c>0812345678</c>) or international (<c>+243812345678</c>) number, at most 20 characters.</summary>
    public string Phone { get; init; } = string.Empty;

    /// <summary>ISO alpha-3 country of the number (default: DR Congo for a local number).</summary>
    public string? Country { get; init; }

    /// <summary>Provider code (inferred from the number when absent), e.g. <c>VODACOM_MPESA_COD</c>.</summary>
    public string? Provider { get; init; }

    /// <summary>Your reference, unique per token: 1 to 100 of <c>A-Z a-z 0-9 . _ : -</c>. Sending it again returns
    /// the existing payment (idempotency). Generated (<c>sdk_&lt;uuid&gt;</c>) when absent, unless
    /// <see cref="LabPayClientOptions.AutoReference"/> is off.</summary>
    public string? Reference { get; init; }

    /// <summary>Public HTTPS URL receiving the final status notification.</summary>
    public string? CallbackUrl { get; init; }

    /// <summary>Text shown to the customer: 4 to 22 letters, digits, spaces.</summary>
    public string? CustomerMessage { get; init; }

    /// <summary>Up to 20 key/value pairs (strings of at most 255 characters; numbers and booleans are converted),
    /// returned as is in the payment and the notifications.</summary>
    public IReadOnlyDictionary<string, object?>? Metadata { get; init; }
}

/// <summary>A refund (<c>POST /V2/refunds</c>).</summary>
public sealed class RefundRequest
{
    /// <summary>Id of the completed V2 deposit to refund (<c>DEP-…</c>).</summary>
    public string PaymentId { get; init; } = string.Empty;

