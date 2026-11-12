using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Text.Encodings.Web;
using System.Text.Json;

namespace Labyrinthe.LabPay.Internal;

/// <summary>
/// Lecture typée du JSON de l'API : tolérante sur la représentation (nombre pour une chaîne, <c>[]</c> PHP pour un
/// objet vide), stricte sur la présence des champs obligatoires. Mêmes règles que <c>node/src/types.ts</c>.
/// <c>error</c> construit l'exception (réponse de l'API ou notification).
/// </summary>
internal sealed class Json
{
    public static readonly Json Api = new("Unexpected API payload", message => new UnexpectedResponseException(message));

    public static readonly Json Webhook = new("Invalid notification payload", message => new InvalidWebhookPayloadException(message));

    private static readonly JsonElement EmptyObject = ParseConstant("{}");

    /// <summary>Échappement minimal (JSON en UTF-8, jamais inséré dans du HTML) : le "+" des numéros part tel quel.</summary>
    private static readonly JsonWriterOptions WriterOptions = new() { Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping };

    private readonly string context;
    private readonly Func<string, LabPayException> error;

    public Json(string context, Func<string, LabPayException> error)
    {
        this.context = context;
        this.error = error;
    }

    /// <summary>Décode un document JSON ; <see langword="null"/> s'il n'est pas valide.</summary>
    public static JsonElement? TryParse(byte[] content)
    {
        try
        {
            using var document = JsonDocument.Parse(content);
            return document.RootElement.Clone();
        }
        catch (JsonException)
        {
            return null;
        }
    }

    public static long? ToLong(JsonElement value) => value.ValueKind switch
    {
        JsonValueKind.Number when value.TryGetInt64(out var l) => l,
        JsonValueKind.String when long.TryParse(value.GetString()?.Trim(), NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out var l) => l,
        _ => null,
    };

    /// <summary>ISO 8601 → <see cref="DateTimeOffset"/> (UTC sans fuseau). Tolérante : une date illisible donne
    /// <see langword="null"/> (la chaîne reste dans <c>Raw</c>).</summary>
    public static DateTimeOffset? ParseDate(string? value)
    {
        if (value is null || value.Trim().Length == 0)
        {
            return null;
        }

        return DateTimeOffset.TryParse(value.Trim(), CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var parsed) ? parsed : null;
    }

    public LabPayException Invalid(string key, string expected) => error($"{context}: \"{key}\" should be {expected}.");

    /// <summary>Objet JSON ; <c>[]</c> (objet vide encodé par PHP) donne un objet vide.</summary>
    public JsonElement Object(JsonElement value, string field)
    {
        if (value.ValueKind == JsonValueKind.Object)
        {
            return value;
        }

        if (value.ValueKind == JsonValueKind.Array && value.GetArrayLength() == 0)
        {
            return EmptyObject;
        }

        throw Invalid(field, "an object");
    }

    /// <summary>Champ objet facultatif : absent ou nul donne un objet vide.</summary>
    public JsonElement OptionalObject(JsonElement data, string key) =>
        data.TryGetProperty(key, out var value) && value.ValueKind != JsonValueKind.Null ? Object(value, key) : EmptyObject;

    /// <summary>Objet tolérant : tout ce qui n'est pas un objet donne un objet vide.</summary>
    public static JsonElement Lenient(JsonElement value) => value.ValueKind == JsonValueKind.Object ? value : EmptyObject;

    public static JsonElement Field(JsonElement data, string key) =>
        data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value) ? value : default;

    public List<JsonElement> Array(JsonElement value, string field)
    {
        if (value.ValueKind != JsonValueKind.Array)
        {
            throw Invalid(field, "an array");
        }

        var items = new List<JsonElement>(value.GetArrayLength());
        foreach (var item in value.EnumerateArray())
        {
            items.Add(item);
        }

        return items;
    }

    /// <summary>Chaîne obligatoire (un nombre est accepté et converti sans perte).</summary>
    public string String(JsonElement data, string key)
    {
        if (data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value))
        {
            if (value.ValueKind == JsonValueKind.String)
            {
                return value.GetString() ?? string.Empty;
            }

            if (value.ValueKind == JsonValueKind.Number)
            {
                // Texte brut du nombre : aucune conversion en double.
                return value.GetRawText();
            }
