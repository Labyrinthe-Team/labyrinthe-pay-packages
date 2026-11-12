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
        _ => "unknown",
    };

    /// <summary>The API value, e.g. <c>deposit</c>.</summary>
    /// <param name="value">The operation.</param>
    /// <returns>The value sent by the API.</returns>
    public static string ToApiString(this Operation value) => value switch
    {
        Operation.Deposit => "deposit",
        Operation.Payout => "payout",
        Operation.Refund => "refund",
        _ => "unknown",
    };

    /// <summary>The API value, e.g. <c>live</c>.</summary>
    /// <param name="value">The mode.</param>
    /// <returns>The value sent by the API.</returns>
    public static string ToApiString(this LabPayMode value) => value switch
    {
        LabPayMode.Live => "live",
        LabPayMode.Test => "test",
        _ => "unknown",
    };

    /// <summary>The API value, e.g. <c>delivered</c>.</summary>
    /// <param name="value">The status.</param>
    /// <returns>The value sent by the API.</returns>
    public static string ToApiString(this NotificationStatus value) => value switch
    {
        NotificationStatus.Delivered => "delivered",
        NotificationStatus.Pending => "pending",
        NotificationStatus.Abandoned => "abandoned",
        _ => "unknown",
    };

    /// <summary>The API value, e.g. <c>payment.completed</c>.</summary>
    /// <param name="value">The event.</param>
    /// <returns>The value sent by the API.</returns>
    public static string ToApiString(this WebhookEventType value) => value switch
    {
        WebhookEventType.PaymentCompleted => "payment.completed",
        WebhookEventType.PaymentFailed => "payment.failed",
        _ => "unknown",
    };

    internal static PaymentStatus ParsePaymentStatus(string? value) => value switch
    {
        "pending" => PaymentStatus.Pending,
        "completed" => PaymentStatus.Completed,
        "failed" => PaymentStatus.Failed,
        _ => PaymentStatus.Unknown,
    };

    internal static Operation ParseOperation(string? value) => value switch
    {
        "deposit" => Operation.Deposit,
        "payout" => Operation.Payout,
        "refund" => Operation.Refund,
        _ => Operation.Unknown,
    };

    internal static LabPayMode ParseMode(string? value) => value switch
    {
        "live" => LabPayMode.Live,
        "test" => LabPayMode.Test,
        _ => LabPayMode.Unknown,
    };

    internal static NotificationStatus ParseNotificationStatus(string? value) => value switch
    {
        "delivered" => NotificationStatus.Delivered,
        "pending" => NotificationStatus.Pending,
        "abandoned" => NotificationStatus.Abandoned,
        _ => NotificationStatus.Unknown,
    };

    internal static WebhookEventType ParseEventType(string? value) => value switch
    {
        "payment.completed" => WebhookEventType.PaymentCompleted,
        "payment.failed" => WebhookEventType.PaymentFailed,
        _ => WebhookEventType.Unknown,
    };

    /// <summary>Refuse <c>Unknown</c> dans un filtre (jamais accepté par l'API).</summary>
    internal static string Filter(string value, string field) =>
        value == "unknown" ? throw new InvalidArgumentException($"{field} cannot be Unknown.") : value;
}
