using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text.RegularExpressions;

namespace Labyrinthe.LabPay.Internal;

/// <summary>
/// Contrôles locaux reprenant les règles de validation de l'API (<c>MobileMoneyPaymentRequest</c>,
/// <c>InitiateRefundRequest</c>, <c>ListPaymentsRequest</c>) : une erreur évidente échoue avant tout appel réseau.
/// Mêmes règles que <c>node/src/internal/input.ts</c>. Usage interne.
/// </summary>
internal static class Input
{
    public const int MaxPerPage = 100;

    private static readonly TimeSpan RegexTimeout = TimeSpan.FromSeconds(1);
    private static readonly Regex Reference = new("^[A-Za-z0-9._:-]{1,100}$", RegexOptions.CultureInvariant, RegexTimeout);
    private static readonly Regex CustomerMessage = new("^[A-Za-z0-9 ]{4,22}$", RegexOptions.CultureInvariant, RegexTimeout);
    private static readonly Regex Code3 = new("^[A-Z]{3}$", RegexOptions.CultureInvariant, RegexTimeout);
    private static readonly Regex ProviderCode = new("^[A-Za-z0-9_]{1,64}$", RegexOptions.CultureInvariant, RegexTimeout);

    /// <summary>Plus grand montant accepté par l'API : 16 chiffres avant la virgule.</summary>
    private static readonly decimal MaxAmount = 9_999_999_999_999_999.99m;

    /// <summary>Montant exact : entier → <c>"1500"</c>, sinon deux décimales → <c>"12.50"</c>.</summary>
    public static string Amount(decimal value, string field = "amount")
    {
        if (decimal.Round(value, 2) != value)
        {
            throw new InvalidArgumentException($"{field} must have at most 2 decimals, e.g. 12.50m.");
        }

        if (value <= 0m || value > MaxAmount)
        {
            throw new InvalidArgumentException($"{field} must be a positive number with at most 2 decimals (e.g. 1500m or 12.50m).");
        }

        return value == decimal.Truncate(value)
            ? value.ToString("0", CultureInfo.InvariantCulture)
            : value.ToString("0.00", CultureInfo.InvariantCulture);
    }

    public static string Currency(string? value)
    {
        var code = (value ?? string.Empty).Trim().ToUpperInvariant();
        if (!Code3.IsMatch(code))
        {
            throw new InvalidArgumentException("currency must be a 3-letter code, e.g. \"CDF\" or \"USD\".");
        }

        return code;
    }

    public static string? Country(string? value)
    {
        if (value is null || value.Trim().Length == 0)
        {
            return null;
        }

        var code = value.Trim().ToUpperInvariant();
        if (!Code3.IsMatch(code))
        {
            throw new InvalidArgumentException("country must be an ISO 3166-1 alpha-3 code, e.g. \"COD\".");
        }

        return code;
    }

    public static string Phone(string? value)
    {
        var text = (value ?? string.Empty).Trim();
        if (text.Length == 0 || text.Length > 20)
        {
            throw new InvalidArgumentException("phone is required and must be at most 20 characters.");
        }

        return text;
    }

    public static string? Provider(string? value)
    {
        if (value is null || value.Trim().Length == 0)
        {
            return null;
        }

        if (!ProviderCode.IsMatch(value.Trim()))
        {
            throw new InvalidArgumentException("provider must be a provider code, e.g. \"VODACOM_MPESA_COD\".");
        }

        return value.Trim();
    }

    public static string? ReferenceOrNull(string? value)
    {
        if (value is null)
        {
            return null;
        }

        if (!Reference.IsMatch(value))
        {
            throw new InvalidArgumentException("reference must be 1 to 100 characters among A-Z a-z 0-9 . _ : -");
        }

        return value;
    }

    public static string? CustomerMessageOrNull(string? value)
    {
        if (value is null || value.Length == 0)
        {
            return null;
        }

        if (!CustomerMessage.IsMatch(value))
        {
            throw new InvalidArgumentException("customerMessage must be 4 to 22 characters (letters, digits, spaces).");
        }

        return value;
    }

    public static string? CallbackUrl(string? value)
    {
        if (value is null || value.Trim().Length == 0)
        {
            return null;
        }

        var url = value.Trim();
        var valid = url.Length <= 2048
            && Uri.TryCreate(url, UriKind.Absolute, out var uri)
            && uri.Scheme == Uri.UriSchemeHttps
            && uri.Host.Length > 0;
        if (!valid)
        {
            throw new InvalidArgumentException("callbackUrl must be a public HTTPS URL (at most 2048 characters).");
        }

        return url;
    }

    /// <summary>Au plus 20 entrées, valeurs scalaires d'au plus 255 caractères, converties en chaînes.</summary>
    public static Dictionary<string, string?>? Metadata(IReadOnlyDictionary<string, object?>? value)
    {
        if (value is null)
        {
            return null;
        }

        if (value.Count > 20)
        {
            throw new InvalidArgumentException("metadata accepts at most 20 entries.");
        }

        var result = new Dictionary<string, string?>(StringComparer.Ordinal);
        foreach (var entry in value)
        {
            string? text = entry.Value switch
            {
                null => null,
                string s => s,
                bool b => b ? "true" : "false",
                decimal d => d.ToString(CultureInfo.InvariantCulture),
                int i => i.ToString(CultureInfo.InvariantCulture),
                long l => l.ToString(CultureInfo.InvariantCulture),
                _ => throw new InvalidArgumentException($"metadata[\"{entry.Key}\"] must be a string."),
            };
            if (text is not null && text.Length > 255)
            {
                throw new InvalidArgumentException($"metadata[\"{entry.Key}\"] must be at most 255 characters.");
            }

            result[entry.Key] = text;
        }

        return result;
    }

    /// <summary>Identifiant LabPay (DEP-…, PAY-…, RFD-…), nettoyé et non encodé.</summary>
    public static string Id(string? value, string field = "id")
    {
        var text = (value ?? string.Empty).Trim();
        if (text.Length == 0 || text.Length > 64)
        {
            throw new InvalidArgumentException($"{field} must be a LabPay identifier, e.g. \"DEP-01JCX…\".");
        }

        return text;
    }

    public static int? PerPage(int? value)
    {
        if (value is < 1 or > MaxPerPage)
        {
            throw new InvalidArgumentException($"perPage must be an integer between 1 and {MaxPerPage}.");
        }

        return value;
    }

    public static int? Page(int? value)
    {
        if (value is < 1)
        {
            throw new InvalidArgumentException("page must be an integer, 1 or more.");
        }

        return value;
    }
}
