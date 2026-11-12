package cd.labyrinthe.labpay.internal;

import cd.labyrinthe.labpay.exception.UnexpectedResponseException;
import java.math.BigDecimal;
import java.math.BigInteger;
import java.time.Instant;
import java.time.LocalDateTime;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.time.format.DateTimeParseException;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;

/**
 * Lecture typée du JSON de l'API (et des notifications) : tolérante sur la représentation (nombre
 * pour une chaîne, {@code []} PHP pour un objet vide), stricte sur les champs obligatoires. Mêmes
 * règles que {@code node/src/types.ts}. Usage interne.
 */
public final class Data {

  /**
   * Début des messages d'erreur ; remplacé par « Invalid notification payload » pour un webhook.
   */
  public static final String CONTEXT = "Unexpected API payload";

  private static final Pattern INTEGER = Pattern.compile("-?[0-9]{1,18}");

  private Data() {}

  /**
   * Objet JSON.
   *
   * @param value valeur décodée
   * @param field nom du champ (message d'erreur)
   * @return l'objet ({@code []} donne un objet vide)
   */
  @SuppressWarnings("unchecked")
  public static Map<String, Object> object(Object value, String field) {
    if (value instanceof Map) {
      return (Map<String, Object>) value;
    }
    if (value instanceof List && ((List<?>) value).isEmpty()) {
      // PHP encode un objet vide en [].
      return Collections.emptyMap();
    }
    throw invalid(field, "an object");
  }

  /**
   * Objet facultatif : absent ou nul donne un objet vide.
   *
   * @param data objet parent
   * @param key champ
   * @return l'objet
   */
  public static Map<String, Object> optionalObject(Map<String, Object> data, String key) {
    Object value = data.get(key);
    return value == null ? Collections.emptyMap() : object(value, key);
  }

  /**
   * Objet facultatif tolérant : tout ce qui n'est pas un objet donne un objet vide.
   *
   * @param value valeur
   * @return l'objet
   */
  @SuppressWarnings("unchecked")
  public static Map<String, Object> lenientObject(Object value) {
    return value instanceof Map ? (Map<String, Object>) value : Collections.emptyMap();
  }

  /**
   * Tableau JSON.
   *
   * @param value valeur décodée
   * @param field nom du champ
   * @return la liste
   */
  public static List<?> array(Object value, String field) {
    if (value instanceof List) {
      return (List<?>) value;
    }
    throw invalid(field, "an array");
  }

  /**
   * Chaîne obligatoire (un nombre est accepté et converti sans perte).
   *
   * @param data objet
   * @param key champ
   * @return la chaîne
   */
  public static String string(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (value instanceof String) {
      return (String) value;
    }
    if (value instanceof BigDecimal) {
      return ((BigDecimal) value).toPlainString();
    }
    if (value instanceof Long || value instanceof BigInteger || value instanceof Integer) {
      return value.toString();
    }
    throw invalid(key, "a string");
  }

  /**
   * Chaîne facultative.
   *
   * @param data objet
   * @param key champ
   * @return la chaîne, ou null si absente ou nulle
   */
  public static String nullableString(Map<String, Object> data, String key) {
    return data.get(key) == null ? null : string(data, key);
  }

  /**
   * Entier facultatif (nombre entier ou chaîne d'entier).
   *
   * @param data objet
   * @param key champ
   * @return l'entier, ou null
   */
  public static Long nullableLong(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (value instanceof Long || value instanceof Integer) {
      return ((Number) value).longValue();
    }
    if (value instanceof BigDecimal) {
      BigDecimal decimal = (BigDecimal) value;
      try {
        return decimal.longValueExact();
      } catch (ArithmeticException e) {
        return null;
      }
    }
    if (value instanceof String && INTEGER.matcher((String) value).matches()) {
      return Long.parseLong((String) value);
    }
    return null;
  }

  /**
   * Entier facultatif borné à {@code int}.
   *
   * @param data objet
   * @param key champ
   * @return l'entier, ou null
   */
  public static Integer nullableInt(Map<String, Object> data, String key) {
    Long value = nullableLong(data, key);
    return value == null || value > Integer.MAX_VALUE || value < Integer.MIN_VALUE
        ? null
        : value.intValue();
  }

  /**
   * Booléen, avec valeur par défaut quand le champ n'est pas un booléen.
   *
   * @param data objet
   * @param key champ
   * @param fallback valeur par défaut
   * @return le booléen
   */
  public static boolean bool(Map<String, Object> data, String key, boolean fallback) {
    Object value = data.get(key);
    return value instanceof Boolean ? (Boolean) value : fallback;
  }

  /**
   * Date ISO 8601 facultative ({@code Z}, {@code +00:00}, microsecondes ; sans fuseau : UTC).
   * Tolérante : une date illisible donne null (la chaîne reste dans {@code raw}).
   *
   * @param data objet
   * @param key champ
   * @return l'instant, ou null
   */
  public static Instant nullableInstant(Map<String, Object> data, String key) {
    Object value = data.get(key);
    if (!(value instanceof String)) {
      return null;
    }
    String text = ((String) value).trim();
    try {
      return OffsetDateTime.parse(text).toInstant();
    } catch (DateTimeParseException e) {
      try {
        return LocalDateTime.parse(text.replace(' ', 'T')).toInstant(ZoneOffset.UTC);
      } catch (DateTimeParseException ignored) {
        return null;
      }
    }
  }

  /**
   * Table de chaînes facultative (métadonnées) : les scalaires sont convertis en chaîne, le reste
   * en null.
   *
   * @param data objet
   * @param key champ
   * @return la table non modifiable, ou null si absente
   */
  public static Map<String, String> nullableStringMap(Map<String, Object> data, String key) {
    if (data.get(key) == null) {
      return null;
    }
    Map<String, String> result = new LinkedHashMap<>();
    for (Map.Entry<String, Object> entry : object(data.get(key), key).entrySet()) {
      Object item = entry.getValue();
      String text;
      if (item instanceof String) {
        text = (String) item;
      } else if (item instanceof BigDecimal) {
        text = ((BigDecimal) item).toPlainString();
      } else if (item instanceof Number || item instanceof Boolean) {
        text = item.toString();
      } else {
        text = null;
      }
      result.put(entry.getKey(), text);
    }
    return Collections.unmodifiableMap(result);
  }

  /**
   * L'erreur « champ invalide ».
   *
   * @param key champ
   * @param expected type attendu
   * @return l'exception
   */
  public static UnexpectedResponseException invalid(String key, String expected) {
    return new UnexpectedResponseException(
        CONTEXT + ": \"" + key + "\" should be " + expected + ".");
  }
}
