using System;
using System.Collections.Generic;
using System.Text;
using System.Text.RegularExpressions;

namespace Labyrinthe.LabPay.Internal;

/// <summary>Tient les secrets et les numéros hors des journaux et des messages. Usage interne.</summary>
internal static class Redact
{
    private static readonly Regex QueryString = new("\\?[^\\s\"']*", RegexOptions.CultureInvariant, TimeSpan.FromSeconds(1));

    /// <summary><c>"test_abcd…wxyz"</c> → <c>"test_…wxyz"</c> ; une clé courte ne révèle rien.</summary>
    public static string ApiKey(string apiKey)
    {
        var prefix = apiKey.StartsWith("test_", StringComparison.Ordinal) ? "test_" : string.Empty;
        var tail = apiKey.Length > 12 ? apiKey.Substring(apiKey.Length - 4) : string.Empty;
        return prefix + "…" + tail;
    }

    /// <summary>Chemin pour les journaux : les valeurs de la requête sont retirées (références, numéros).</summary>
    public static string Path(string path, IReadOnlyList<KeyValuePair<string, string>> query)
    {
        if (query.Count == 0)
        {
            return path;
        }

        var builder = new StringBuilder(path).Append('?');
        for (var i = 0; i < query.Count; i++)
        {
            if (i > 0)
            {
                builder.Append('&');
            }

            builder.Append(query[i].Key).Append("=…");
        }

        return builder.ToString();
    }

    /// <summary>Retire la requête d'une URL citée dans un message d'erreur.</summary>
    public static string StripQueryStrings(string text) => QueryString.Replace(text, "?…");
}
