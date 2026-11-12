using System;
using System.Collections.Generic;
using System.Globalization;

namespace Labyrinthe.LabPay;

/// <summary>
/// Base class of every exception thrown by the SDK: catch it to handle any LabPay failure in one place.
/// </summary>
/// <remarks>Switch on <see cref="Code"/> (stable) or on the type, never on the message: API messages are in French,
/// for humans, and may change. Exceptions never contain the API key.</remarks>
public class LabPayException : Exception
{
    /// <summary>Creates an exception.</summary>
    /// <param name="message">Human-readable message.</param>
    /// <param name="code">Stable machine-readable code, e.g. <c>INSUFFICIENT_BALANCE</c> or <c>TIMEOUT</c>.</param>
    /// <param name="statusCode">HTTP status, <see langword="null"/> without a response.</param>
    public LabPayException(string message, string code, int? statusCode = null)
        : base(message)
    {
        Code = code;
        StatusCode = statusCode;
    }

    /// <summary>Stable code: an API error code (<c>VALIDATION_ERROR</c>…) or an SDK code (<c>CONNECTION_ERROR</c>,
    /// <c>TIMEOUT</c>, <c>INVALID_ARGUMENT</c>…).</summary>
    public string Code { get; }

    /// <summary>HTTP status of the response; <see langword="null"/> when no response was received (network error,
    /// timeout, local validation, webhook verification…).</summary>
    public int? StatusCode { get; }
}

/// <summary>The API answered with an error (<c>{"message", "code", "errors"?}</c>).</summary>
/// <remarks>Subclasses exist for the codes an integration usually handles; any other code (including codes added
/// later) is thrown as a plain <see cref="ApiException"/>.</remarks>
public class ApiException : LabPayException
{
    private static readonly IReadOnlyDictionary<string, IReadOnlyList<string>> NoErrors =
        new Dictionary<string, IReadOnlyList<string>>(StringComparer.Ordinal);

    private static readonly IReadOnlyDictionary<string, string> NoHeaders =
        new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers (case-insensitive names).</param>
    public ApiException(
        string message,
        string code,
        int statusCode,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null,
        IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode)
    {
        Errors = errors ?? NoErrors;
        Headers = headers ?? NoHeaders;
    }

    /// <summary>Per-field messages of a validation error, e.g. <c>{"amount": ["…"]}</c>; empty otherwise.</summary>
    public IReadOnlyDictionary<string, IReadOnlyList<string>> Errors { get; }

    /// <summary>Response headers (case-insensitive names).</summary>
    public IReadOnlyDictionary<string, string> Headers { get; }

    /// <summary>Mode of the key that made the request (<c>X-Labyrinthe-Mode</c>): <c>live</c>, <c>test</c>, or
    /// <see langword="null"/>.</summary>
    public string? Mode =>
        Headers.TryGetValue("X-Labyrinthe-Mode", out var mode) && (mode == "live" || mode == "test") ? mode : null;

    /// <summary>Seconds from the <c>Retry-After</c> header; <see langword="null"/> when the API does not say.</summary>
    public int? RetryAfter =>
        Headers.TryGetValue("Retry-After", out var value)
        && int.TryParse(value.Trim(), NumberStyles.None, CultureInfo.InvariantCulture, out var seconds)
            ? seconds
            : null;
}

/// <summary>401 <c>UNAUTHENTICATED</c>: missing or unknown API token / test key.</summary>
public class AuthenticationException : ApiException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers.</param>
    public AuthenticationException(string message, string code, int statusCode, IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, errors, headers)
    {
    }
}

/// <summary>403: <c>TOKEN_BLOCKED</c>, <c>MERCHANT_INACTIVE</c>, <c>KYC_REQUIRED</c> or <c>LIVE_TOKEN_REQUIRED</c>.</summary>
public class PermissionException : ApiException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers.</param>
    public PermissionException(string message, string code, int statusCode, IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, errors, headers)
    {
    }
}

/// <summary>404: <c>PAYMENT_NOT_FOUND</c>, <c>DEPOSIT_NOT_FOUND</c> or <c>NOTIFICATION_NOT_FOUND</c> (for this token and mode).</summary>
public class NotFoundException : ApiException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers.</param>
    public NotFoundException(string message, string code, int statusCode, IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, errors, headers)
    {
    }
}

/// <summary>409: <c>NOT_A_PAYOUT</c>, <c>NOT_CANCELLABLE</c>, <c>DEPOSIT_NOT_COMPLETED</c>, <c>REFUND_IN_PROGRESS</c>,
/// <c>DEPOSIT_ALREADY_REFUNDED</c>.</summary>
public class ConflictException : ApiException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers.</param>
    public ConflictException(string message, string code, int statusCode, IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, errors, headers)
    {
    }
}

/// <summary>422 without a payment: refused before any payment was created (<c>INVALID_CURRENCY</c>,
/// <c>UNSUPPORTED_COUNTRY</c>, <c>UNSUPPORTED_PROVIDER</c>, <c>INVALID_AMOUNT</c>…).</summary>
/// <remarks>A payment refused by the provider is not an exception: it is returned with the <c>failed</c> status.</remarks>
public class InvalidRequestException : ApiException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
    /// <param name="message">Message (in French when it comes from the API).</param>
    /// <param name="code">Stable code.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="errors">Per-field messages.</param>
    /// <param name="headers">Response headers.</param>
    public InvalidRequestException(string message, string code, int statusCode, IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, errors, headers)
    {
    }
}

/// <summary>422 <c>VALIDATION_ERROR</c> (or 422 without code on invalid filters): see <see cref="ApiException.Errors"/>.</summary>
public class ValidationException : InvalidRequestException
{
    /// <summary>Creates the exception (normally done by the SDK).</summary>
