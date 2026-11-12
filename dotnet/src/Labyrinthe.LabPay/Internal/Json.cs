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
        }

        throw Invalid(key, "a string");
    }

    public string? NullableString(JsonElement data, string key) => IsNull(data, key) ? null : String(data, key);

    public static int? NullableInt(JsonElement data, string key)
    {
        if (data.ValueKind != JsonValueKind.Object || !data.TryGetProperty(key, out var value))
        {
            return null;
        }

        return ToLong(value) is long number && number >= int.MinValue && number <= int.MaxValue ? (int)number : null;
    }

    public static long? NullableLong(JsonElement data, string key) =>
        data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value) ? ToLong(value) : null;

    public static bool Bool(JsonElement data, string key, bool fallback)
    {
        if (data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value))
        {
            if (value.ValueKind == JsonValueKind.True)
            {
                return true;
            }

            if (value.ValueKind == JsonValueKind.False)
            {
                return false;
            }
        }

        return fallback;
    }

    public static DateTimeOffset? NullableDate(JsonElement data, string key) =>
        data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value) && value.ValueKind == JsonValueKind.String
            ? ParseDate(value.GetString())
            : null;

    /// <summary>Table de chaînes facultative (métadonnées) : scalaires convertis en chaîne, le reste en null.</summary>
    public Dictionary<string, string?>? NullableStringMap(JsonElement data, string key)
    {
        if (IsNull(data, key))
        {
            return null;
        }

        var result = new Dictionary<string, string?>(StringComparer.Ordinal);
        foreach (var property in Object(data.GetProperty(key), key).EnumerateObject())
        {
            result[property.Name] = property.Value.ValueKind switch
            {
                JsonValueKind.String => property.Value.GetString(),
                JsonValueKind.Number => property.Value.GetRawText(),
                JsonValueKind.True => "true",
                JsonValueKind.False => "false",
                _ => null,
            };
        }

        return result;
    }

    /// <summary>Valeur brute (copie indépendante du document) ; <see langword="null"/> si absente ou nulle.</summary>
    public static JsonElement? Raw(JsonElement data, string key) =>
        data.ValueKind == JsonValueKind.Object && data.TryGetProperty(key, out var value) && value.ValueKind != JsonValueKind.Null
            ? value.Clone()
            : null;

    /// <summary>Corps de requête JSON écrit sans réflexion (compatible trimming / AOT). Les champs nuls de premier niveau
    /// sont omis ; les valeurs nulles d'une table (métadonnées) sont écrites.</summary>
    public static byte[] Write(IEnumerable<KeyValuePair<string, object?>> fields)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream, WriterOptions))
        {
            writer.WriteStartObject();
            foreach (var field in fields)
            {
                switch (field.Value)
                {
                    case null:
                        break;
                    case string s:
                        writer.WriteString(field.Key, s);
                        break;
                    case IEnumerable<KeyValuePair<string, string?>> map:
                        writer.WriteStartObject(field.Key);
                        foreach (var entry in map)
                        {
                            if (entry.Value is null)
                            {
                                writer.WriteNull(entry.Key);
                            }
                            else
                            {
                                writer.WriteString(entry.Key, entry.Value);
                            }
                        }

                        writer.WriteEndObject();
                        break;
                    default:
                        throw new ArgumentException("Unsupported JSON value.", nameof(fields));
                }
            }

            writer.WriteEndObject();
        }

        return stream.ToArray();
    }

    private static bool IsNull(JsonElement data, string key) =>
        data.ValueKind != JsonValueKind.Object || !data.TryGetProperty(key, out var value) || value.ValueKind == JsonValueKind.Null;

    private static JsonElement ParseConstant(string json)
    {
        using var document = JsonDocument.Parse(json);
        return document.RootElement.Clone();
    }
}
