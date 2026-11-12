using System;
using System.Collections.Generic;
using System.Text.Json;

namespace Labyrinthe.LabPay.Internal;

/// <summary>
/// Convertit une réponse d'erreur en exception typée : par code d'abord, puis par statut HTTP, sinon
/// <see cref="ApiException"/>. Même table que <c>node/src/errors.ts</c>. Usage interne.
/// </summary>
internal static class ErrorMapper
{
    /// <summary>Code donné par le SDK à un 429 du limiteur (l'API n'envoie pas de code).</summary>
    public const string TooManyRequests = "TOO_MANY_REQUESTS";

    /// <summary>Code donné par le SDK quand l'API n'a envoyé aucun code.</summary>
    public const string HttpError = "HTTP_ERROR";

    private delegate ApiException Factory(
        string message,
        string code,
        int status,
        IReadOnlyDictionary<string, IReadOnlyList<string>> errors,
        IReadOnlyDictionary<string, string> headers);

    private static readonly Dictionary<string, Factory> ByCode = BuildTable();

    /// <summary>Codes de l'API qui ont une exception dédiée (test de contrat).</summary>
    public static IEnumerable<string> KnownCodes => ByCode.Keys;

    public static ApiException FromResponse(int status, JsonElement? body, IReadOnlyDictionary<string, string> headers)
    {
        var root = body is { ValueKind: JsonValueKind.Object } value ? value : default;
        var errors = FieldErrors(root);
        var code = root.ValueKind == JsonValueKind.Object
            && root.TryGetProperty("code", out var rawCode)
            && rawCode.ValueKind == JsonValueKind.String
            && rawCode.GetString() is { Length: > 0 } c
                ? c
                : null;
        if (code is null)
        {
            code = status == 429 ? TooManyRequests : status == 422 && errors.Count > 0 ? "VALIDATION_ERROR" : HttpError;
        }

        var message = root.ValueKind == JsonValueKind.Object
            && root.TryGetProperty("message", out var rawMessage)
            && rawMessage.ValueKind == JsonValueKind.String
            && rawMessage.GetString() is { Length: > 0 } m
                ? m
                : $"The LabPay API returned HTTP {status}.";
        var factory = ByCode.TryGetValue(code, out var known) ? known : ForStatus(status);
        return factory(message, code, status, errors, headers);
    }

    private static Dictionary<string, Factory> BuildTable()
    {
        var table = new Dictionary<string, Factory>(StringComparer.Ordinal);
        void Add(Factory factory, params string[] codes)
        {
            foreach (var code in codes)
            {
                table[code] = factory;
            }
        }

        Add((m, c, s, e, h) => new AuthenticationException(m, c, s, e, h), "UNAUTHENTICATED");
        Add((m, c, s, e, h) => new PermissionException(m, c, s, e, h), "TOKEN_BLOCKED", "MERCHANT_INACTIVE", "KYC_REQUIRED", "LIVE_TOKEN_REQUIRED");
        Add((m, c, s, e, h) => new NotFoundException(m, c, s, e, h), "PAYMENT_NOT_FOUND", "DEPOSIT_NOT_FOUND", "NOTIFICATION_NOT_FOUND");
        Add(
            (m, c, s, e, h) => new ConflictException(m, c, s, e, h),
            "NOT_A_PAYOUT",
            "NOT_CANCELLABLE",
            "DEPOSIT_NOT_COMPLETED",
            "REFUND_IN_PROGRESS",
            "DEPOSIT_ALREADY_REFUNDED");
        Add((m, c, s, e, h) => new ValidationException(m, c, s, e, h), "VALIDATION_ERROR");
        Add((m, c, s, e, h) => new InvalidPhoneNumberException(m, c, s, e, h), "INVALID_PHONE_NUMBER");
        Add((m, c, s, e, h) => new InvalidRequestException(m, c, s, e, h), "UNSUPPORTED_COUNTRY", "INVALID_CURRENCY", "UNSUPPORTED_PROVIDER", "INVALID_AMOUNT");
        Add((m, c, s, e, h) => new AmountOutOfRangeException(m, c, s, e, h), "AMOUNT_TOO_SMALL", "AMOUNT_TOO_LARGE", "AMOUNT_OUT_OF_BOUNDS");
        Add((m, c, s, e, h) => new InsufficientBalanceException(m, c, s, e, h), "INSUFFICIENT_BALANCE");
        Add((m, c, s, e, h) => new DailyLimitReachedException(m, c, s, e, h), "DAILY_LIMIT_REACHED");
        Add(
            (m, c, s, e, h) => new ServiceUnavailableException(m, c, s, e, h),
            "MAINTENANCE",
            "API_DISABLED",
            "DEPOSITS_DISABLED",
            "WITHDRAWALS_DISABLED",
            "PROVIDER_TEMPORARILY_UNAVAILABLE",
            "GATEWAY_UNAVAILABLE",
            "V2_DISABLED",
            "TEST_MODE_DISABLED");
        return table;
    }

    private static Factory ForStatus(int status) => status switch
    {
        401 => (m, c, s, e, h) => new AuthenticationException(m, c, s, e, h),
        403 => (m, c, s, e, h) => new PermissionException(m, c, s, e, h),
        404 => (m, c, s, e, h) => new NotFoundException(m, c, s, e, h),
        409 => (m, c, s, e, h) => new ConflictException(m, c, s, e, h),
        422 => (m, c, s, e, h) => new InvalidRequestException(m, c, s, e, h),
        429 => (m, c, s, e, h) => new RateLimitException(m, c, s, e, h),
        >= 500 => (m, c, s, e, h) => new ServiceUnavailableException(m, c, s, e, h),
        _ => (m, c, s, e, h) => new ApiException(m, c, s, e, h),
    };

    private static Dictionary<string, IReadOnlyList<string>> FieldErrors(JsonElement root)
    {
        var result = new Dictionary<string, IReadOnlyList<string>>(StringComparer.Ordinal);
        if (root.ValueKind != JsonValueKind.Object
            || !root.TryGetProperty("errors", out var errors)
            || errors.ValueKind != JsonValueKind.Object)
        {
            return result;
        }

        foreach (var field in errors.EnumerateObject())
        {
            var messages = new List<string>();
            if (field.Value.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in field.Value.EnumerateArray())
                {
                    if (item.ValueKind == JsonValueKind.String && item.GetString() is { } text)
                    {
                        messages.Add(text);
                    }
                }
            }
            else if (field.Value.ValueKind == JsonValueKind.String && field.Value.GetString() is { } single)
            {
                messages.Add(single);
            }

            result[field.Name] = messages;
        }

        return result;
    }
}
