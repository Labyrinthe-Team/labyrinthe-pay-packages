namespace Labyrinthe.LabPay;

/// <summary>Public status of a payment. <see cref="Completed"/> and <see cref="Failed"/> are final.</summary>
public enum PaymentStatus
{
    /// <summary>A value this SDK version does not know yet (see <c>Raw</c>).</summary>
    Unknown = 0,

    /// <summary>Waiting for the customer or the provider.</summary>
    Pending,

    /// <summary>Final: deposit credited to you, payout or refund sent. Deliver only on this status.</summary>
    Completed,

    /// <summary>Final: refused, expired or cancelled; see <c>Failure</c>.</summary>
    Failed,
}

/// <summary>Kind of payment.</summary>
public enum Operation
{
    /// <summary>A value this SDK version does not know yet.</summary>
    Unknown = 0,

    /// <summary>Collection from a customer (<c>DEP-…</c>).</summary>
    Deposit,

    /// <summary>Money sent to a wallet (<c>PAY-…</c>).</summary>
    Payout,

    /// <summary>Refund of a deposit (<c>RFD-…</c>).</summary>
    Refund,
}

/// <summary>Mode of the key used: live token or test key (<c>test_…</c>).</summary>
public enum LabPayMode
{
    /// <summary>A value this SDK version does not know yet.</summary>
    Unknown = 0,

    /// <summary>Production: real money.</summary>
    Live,

    /// <summary>Sandbox: no real money, separate balances and payments.</summary>
    Test,
}

/// <summary>Delivery status of a notification.</summary>
public enum NotificationStatus
{
    /// <summary>A value this SDK version does not know yet.</summary>
    Unknown = 0,

    /// <summary>Your endpoint answered 2xx.</summary>
    Delivered,

    /// <summary>Waiting for (another) attempt.</summary>
    Pending,

    /// <summary>Every attempt failed; it can be resent.</summary>
    Abandoned,
}

/// <summary>Event of a notification (<c>X-Labyrinthe-Event</c> header and <c>event</c> field).</summary>
public enum WebhookEventType
{
    /// <summary>An event this SDK version does not know yet (see <c>Name</c>).</summary>
    Unknown = 0,

    /// <summary><c>payment.completed</c>.</summary>
    PaymentCompleted,

    /// <summary><c>payment.failed</c>.</summary>
    PaymentFailed,
}

/// <summary>Conversions between the enums and the values of the API.</summary>
public static class ApiValues
{
    /// <summary>The API value, e.g. <c>pending</c>.</summary>
    /// <param name="value">The status.</param>
    /// <returns>The value sent by the API.</returns>
    public static string ToApiString(this PaymentStatus value) => value switch
    {
        PaymentStatus.Pending => "pending",
        PaymentStatus.Completed => "completed",
        PaymentStatus.Failed => "failed",
