using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text;
using System.Text.Json;

namespace Labyrinthe.LabPay;

/// <summary>
/// A V2 payment: deposit (<c>DEP-…</c>), payout (<c>PAY-…</c>) or refund (<c>RFD-…</c>). Same shape in API responses
/// and in notifications.
/// </summary>
/// <remarks>Amounts are strings with 2 decimals (<c>"1500.00"</c>), never floating-point numbers: use
/// <see cref="AmountValue"/> for an exact <see cref="decimal"/>. <see cref="object.ToString"/> does not show the phone
/// number nor the metadata.</remarks>
public sealed record Payment
{
    /// <summary>LabPay identifier: <c>DEP-…</c>, <c>PAY-…</c> or <c>RFD-…</c>.</summary>
    public string Id { get; init; } = string.Empty;

    /// <summary>Your reference (unique per token and mode), or <see langword="null"/>.</summary>
    public string? Reference { get; init; }

    /// <summary>Kind of payment.</summary>
    public Operation Operation { get; init; }

    /// <summary>False for a test payment (sandbox).</summary>
    public bool Livemode { get; init; } = true;

    /// <summary>Public status: rely on it, not on <see cref="GatewayStatus"/>.</summary>
    public PaymentStatus Status { get; init; }

    /// <summary>Raw aggregator status, informational (e.g. <c>ACCEPTED</c>, <c>ENQUEUED</c>).</summary>
    public string? GatewayStatus { get; init; }

    /// <summary>Deposit: credited to you. Payout: received by the recipient. Refund: refunded.</summary>
    public string Amount { get; init; } = "0.00";

    /// <summary>LabPay fees.</summary>
    public string? Fee { get; init; }

    /// <summary>Deposit: paid by the customer. Payout: debited from your balance.</summary>
    public string? TotalAmount { get; init; }

    /// <summary><c>CDF</c> or <c>USD</c>.</summary>
    public string Currency { get; init; } = string.Empty;

    /// <summary>ISO 3166-1 alpha-3 country of the number, e.g. <c>COD</c>.</summary>
    public string? Country { get; init; }

    /// <summary>Phone number, in the local format of the country. Personal data: mask it in your logs.</summary>
    public string? Phone { get; init; }

    /// <summary>Provider code, e.g. <c>VODACOM_MPESA_COD</c>.</summary>
    public string? Provider { get; init; }

    /// <summary>Provider reference (the one in the customer's SMS).</summary>
    public string? ProviderTransactionId { get; init; }

    /// <summary>Why the payment failed, or <see langword="null"/>.</summary>
    public PaymentFailure? Failure { get; init; }

    /// <summary>Your metadata, or <see langword="null"/>.</summary>
    public IReadOnlyDictionary<string, string?>? Metadata { get; init; }

    /// <summary>Creation date.</summary>
    public DateTimeOffset? CreatedAt { get; init; }

    /// <summary>Final status date, <see langword="null"/> while pending.</summary>
    public DateTimeOffset? CompletedAt { get; init; }

    /// <summary>Deposits only: amount already refunded or being refunded.</summary>
    public string? RefundedAmount { get; init; }

    /// <summary>Refunds only: id of the refunded deposit.</summary>
    public string? RefundedPaymentId { get; init; }

    /// <summary>The payment as returned by the API.</summary>
    public JsonElement Raw { get; init; }

    /// <summary>The amount as an exact decimal, e.g. <c>1500.00m</c>.</summary>
    public decimal AmountValue => decimal.Parse(Amount, NumberStyles.AllowDecimalPoint | NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture);

    /// <summary>Waiting for the customer or the provider.</summary>
    public bool IsPending => Status == PaymentStatus.Pending;

    /// <summary>Deposit: your balance is credited. Payout / refund: the money has left. Deliver only on this status.</summary>
    public bool IsCompleted => Status == PaymentStatus.Completed;

    /// <summary>Failed or cancelled; see <see cref="Failure"/>. Payout / refund: your balance is credited back.</summary>
    public bool IsFailed => Status == PaymentStatus.Failed;

    /// <summary><c>completed</c> or <c>failed</c>: the payment will no longer change.</summary>
    public bool IsFinal => IsCompleted || IsFailed;

    /// <summary>A payout queued by the provider (<c>gateway_status: ENQUEUED</c>): it can be cancelled.</summary>
    public bool IsQueued => IsPending && GatewayStatus == "ENQUEUED";

    /// <summary>A sandbox payment (test key).</summary>
    public bool IsTest => !Livemode;

    private bool PrintMembers(StringBuilder builder)
    {
        builder.Append("Id = ").Append(Id)
            .Append(", Reference = ").Append(Reference)
            .Append(", Operation = ").Append(Operation.ToApiString())
            .Append(", Status = ").Append(Status.ToApiString())
            .Append(", Amount = ").Append(Amount).Append(' ').Append(Currency)
            .Append(", Livemode = ").Append(Livemode ? "true" : "false");
        return true;
    }
}

/// <summary>Why a payment failed, as passed on by LabPay.</summary>
public sealed record PaymentFailure
{
    /// <summary>Stable code, e.g. <c>PAYER_LIMIT_REACHED</c>.</summary>
    public string Code { get; init; } = string.Empty;

    /// <summary>Human-readable message.</summary>
    public string? Message { get; init; }
}

/// <summary>Balance of one currency, in the mode of the key.</summary>
public sealed record Balance
{
    /// <summary><c>CDF</c> or <c>USD</c>.</summary>
    public string Currency { get; init; } = string.Empty;

    /// <summary>String with 2 decimals, e.g. <c>"125000.00"</c>.</summary>
    public string Amount { get; init; } = "0.00";

    /// <summary>Last change.</summary>
    public DateTimeOffset? UpdatedAt { get; init; }

    /// <summary>The balance as an exact decimal.</summary>
    public decimal Value => decimal.Parse(Amount, NumberStyles.AllowDecimalPoint | NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture);
}

/// <summary>The merchant account seen with the current key (<c>GET /V2/account</c>).</summary>
/// <remarks><see cref="object.ToString"/> masks the test key and the signing secret.</remarks>
public sealed record Account
{
    /// <summary>Mode of the key.</summary>
    public LabPayMode Mode { get; init; }

    /// <summary>Merchant name.</summary>
    public string? Merchant { get; init; }

    /// <summary>True once the token is enabled for production; otherwise live collections have a daily limit.</summary>
    public bool LiveEnabled { get; init; }

    /// <summary>Whether the merchant identity is verified.</summary>
    public bool KycVerified { get; init; }

    /// <summary>Deposit fee rate between 0 and 1 (e.g. <c>"0.0350"</c> = 3.5 %).</summary>
    public string? DepositFeeRate { get; init; }

    /// <summary>Payout fee rate between 0 and 1.</summary>
    public string? PayoutFeeRate { get; init; }

    /// <summary>The test key (<c>test_…</c>).</summary>
    public string? TestKey { get; init; }

    /// <summary>Signing secret (<c>whsec_…</c>). Live token only: <see langword="null"/> with the test key.</summary>
    public string? WebhookSecret { get; init; }

    /// <summary>During a rotation: expiry of the previous secret.</summary>
    public DateTimeOffset? WebhookSecretPreviousExpiresAt { get; init; }

    private bool PrintMembers(StringBuilder builder)
    {
        builder.Append("Mode = ").Append(Mode.ToApiString())
            .Append(", Merchant = ").Append(Merchant)
            .Append(", LiveEnabled = ").Append(LiveEnabled ? "true" : "false")
            .Append(", KycVerified = ").Append(KycVerified ? "true" : "false")
            .Append(", TestKey = ").Append(TestKey is null ? "null" : Internal.Redact.ApiKey(TestKey))
            .Append(", WebhookSecret = ").Append(WebhookSecret is null ? "null" : "whsec_****");
        return true;
    }
}

