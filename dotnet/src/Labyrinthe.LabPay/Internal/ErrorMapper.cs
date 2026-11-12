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
