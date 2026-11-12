using System;

namespace Labyrinthe.LabPay;

/// <summary>Options of <see cref="LabPayClient"/>. Bindable from configuration (section <c>LabPay</c>).</summary>
public sealed class LabPayClientOptions
{
    /// <summary>Production API root (<c>/V2</c> is added).</summary>
    public const string DefaultBaseUrl = "https://api.labyrinthe-rdc.com/api";

    /// <summary>Live token or test key (<c>test_…</c>); defaults to the <c>LABPAY_API_KEY</c> environment variable.
    /// Keep it server-side.</summary>
    public string? ApiKey { get; set; }

    /// <summary>API root; defaults to <c>LABPAY_BASE_URL</c>, then <see cref="DefaultBaseUrl"/>. A bare host
    /// (<c>http://localhost:8000</c>) means <c>…/api</c>; <c>…/api/V2</c> is accepted too.</summary>
    public string? BaseUrl { get; set; }

    /// <summary>Timeout of each attempt (default 30 s).</summary>
    public TimeSpan Timeout { get; set; } = TimeSpan.FromSeconds(30);

    /// <summary>Automatic retries, only when they are safe (default 2; 0 disables them; max 10).</summary>
    public int MaxRetries { get; set; } = 2;

    /// <summary>Longest wait accepted before a retry (<c>Retry-After</c>), default 10 s. Beyond it, the error is thrown.</summary>
    public TimeSpan MaxRetryWait { get; set; } = TimeSpan.FromSeconds(10);

    /// <summary>Generate a <c>reference</c> (<c>sdk_&lt;uuid&gt;</c>) for payments sent without one, so that they can
    /// be retried without risk of a double payment (default <see langword="true"/>).</summary>
    public bool AutoReference { get; set; } = true;

    /// <summary>Appended to the <c>User-Agent</c> (integrations, e.g. <c>my-shop/2.1</c>).</summary>
    public string? UserAgentSuffix { get; set; }

    /// <summary>Optional log sink (never receives the key, a body or query values).</summary>
    public ILabPayLogger? Logger { get; set; }

    /// <summary>A copy of these options (the logger instance is shared).</summary>
    /// <returns>The copy.</returns>
    public LabPayClientOptions Clone() => (LabPayClientOptions)MemberwiseClone();

    /// <inheritdoc/>
    public override string ToString() =>
        $"LabPayClientOptions {{ ApiKey = {(ApiKey is null ? "null" : Internal.Redact.ApiKey(ApiKey))}, BaseUrl = {BaseUrl}, " +
        $"Timeout = {Timeout}, MaxRetries = {MaxRetries}, MaxRetryWait = {MaxRetryWait}, AutoReference = {(AutoReference ? "true" : "false")} }}";
}
