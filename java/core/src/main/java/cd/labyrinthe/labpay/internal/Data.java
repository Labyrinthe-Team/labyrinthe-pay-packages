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

