package cd.labyrinthe.labpay.internal;

import java.util.Map;
import java.util.StringJoiner;
import java.util.regex.Pattern;

/** Tient les secrets et les numéros hors des journaux et des messages. Usage interne. */
public final class Redact {

  private static final Pattern QUERY_STRING = Pattern.compile("\\?[^\\s\"']*");

  private Redact() {}

  /**
   * {@code "test_abcd…wxyz"} → {@code "test_…wxyz"} ; une clé courte ne révèle rien.
   *
   * @param key clé ou token
   * @return la clé masquée
   */
  public static String apiKey(String key) {
    String prefix = key.startsWith("test_") ? "test_" : "";
    return prefix + "…" + (key.length() > 12 ? key.substring(key.length() - 4) : "");
  }

  /**
   * Chemin pour les journaux : les valeurs de la requête sont retirées (références, numéros).
   *
   * @param path chemin
   * @param query paramètres
   * @return {@code /payments?reference=…}
   */
  public static String path(String path, Map<String, String> query) {
    if (query.isEmpty()) {
      return path;
    }
    StringJoiner joiner = new StringJoiner("&", path + "?", "");
    query.keySet().forEach(name -> joiner.add(name + "=…"));
    return joiner.toString();
  }

  /**
   * Retire la requête d'une URL citée dans un message d'erreur.
   *
   * @param text message
   * @return le message sans chaîne de requête
   */
  public static String stripQueryStrings(String text) {
    return text == null ? "" : QUERY_STRING.matcher(text).replaceAll("?…");
  }
}
