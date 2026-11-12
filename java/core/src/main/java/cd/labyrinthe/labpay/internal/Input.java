package cd.labyrinthe.labpay.internal;

import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.net.URI;
import java.net.URISyntaxException;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;

/**
 * Contrôles locaux reprenant les règles de validation de l'API ({@code MobileMoneyPaymentRequest},
 * {@code InitiateRefundRequest}, {@code ListPaymentsRequest}) : une erreur évidente échoue avant
 * tout appel réseau. Mêmes règles que {@code node/src/internal/input.ts}. Usage interne.
 */
public final class Input {

  /** Taille de page maximale de l'API. */
  public static final int MAX_PER_PAGE = 100;

  private static final Pattern AMOUNT = Pattern.compile("^\\d{1,16}(\\.\\d{1,2})?$");
  private static final Pattern ZERO = Pattern.compile("^0+(\\.0+)?$");
  private static final Pattern REFERENCE = Pattern.compile("^[A-Za-z0-9._:-]{1,100}$");
  private static final Pattern CUSTOMER_MESSAGE = Pattern.compile("^[A-Za-z0-9 ]{4,22}$");
  private static final Pattern CODE3 = Pattern.compile("^[A-Z]{3}$");
  private static final Pattern PROVIDER = Pattern.compile("^[A-Za-z0-9_]{1,64}$");
  private static final Pattern DATE = Pattern.compile("^\\d{4}-\\d{2}-\\d{2}$");

  private Input() {}

  /**
   * Montant exact : chaîne {@code "1500"} / {@code "12.50"}, ou décimal d'au plus 2 décimales.
   *
   * @param value chaîne ou {@link BigDecimal}
   * @param field nom du champ
   * @return le montant tel qu'envoyé
   */
  public static String amount(Object value, String field) {
    String text;
    if (value instanceof BigDecimal) {
      BigDecimal decimal = (BigDecimal) value;
      BigDecimal stripped = decimal.stripTrailingZeros();
      if (stripped.scale() > 2) {
        throw new InvalidArgumentException(
            field + " must have at most 2 decimals; pass a string such as \"12.50\".");
      }
      text =
          stripped.scale() <= 0
              ? stripped.toBigInteger().toString()
              : decimal.setScale(2, RoundingMode.UNNECESSARY).toPlainString();
    } else if (value instanceof String) {
      text = ((String) value).trim();
    } else {
      throw new InvalidArgumentException(field + " is required.");
    }
    if (!AMOUNT.matcher(text).matches() || ZERO.matcher(text).matches()) {
      throw new InvalidArgumentException(
          field
              + " must be a positive number with at most 2 decimals (e.g. \"1500\" or \"12.50\").");
    }
    return text;
  }

  /**
   * Devise : 3 lettres, mise en majuscules.
   *
   * @param value devise
   * @return le code
   */
  public static String currency(String value) {
    String code = value == null ? "" : value.trim().toUpperCase(Locale.ROOT);
    if (!CODE3.matcher(code).matches()) {
      throw new InvalidArgumentException(
          "currency must be a 3-letter code, e.g. \"CDF\" or \"USD\".");
    }
    return code;
  }

  /**
   * Pays ISO alpha-3 facultatif.
   *
   * @param value pays
   * @return le code, ou null
   */
  public static String country(String value) {
    if (value == null || value.trim().isEmpty()) {
      return null;
    }
    String code = value.trim().toUpperCase(Locale.ROOT);
    if (!CODE3.matcher(code).matches()) {
      throw new InvalidArgumentException(
          "country must be an ISO 3166-1 alpha-3 code, e.g. \"COD\".");
    }
    return code;
  }

  /**
   * Numéro : obligatoire, au plus 20 caractères.
   *
   * @param value numéro
   * @return le numéro
   */
  public static String phone(String value) {
    String text = value == null ? "" : value.trim();
    if (text.isEmpty() || text.length() > 20) {
      throw new InvalidArgumentException("phone is required and must be at most 20 characters.");
    }
    return text;
  }

  /**
   * Code d'opérateur facultatif.
   *
   * @param value code
   * @return le code, ou null
   */
  public static String provider(String value) {
    if (value == null || value.trim().isEmpty()) {
      return null;
    }
    if (!PROVIDER.matcher(value.trim()).matches()) {
      throw new InvalidArgumentException(
          "provider must be a provider code, e.g. \"VODACOM_MPESA_COD\".");
    }
    return value.trim();
  }

  /**
   * Référence facultative : 1 à 100 caractères parmi {@code A-Z a-z 0-9 . _ : -}.
   *
   * @param value référence
   * @return la référence, ou null
   */
  public static String reference(String value) {
    if (value == null) {
      return null;
    }
    if (!REFERENCE.matcher(value).matches()) {
      throw new InvalidArgumentException(
          "reference must be 1 to 100 characters among A-Z a-z 0-9 . _ : -");
    }
    return value;
  }

  /**
   * Message affiché au client : 4 à 22 lettres, chiffres, espaces.
   *
   * @param value message
   * @return le message, ou null
   */
  public static String customerMessage(String value) {
    if (value == null || value.isEmpty()) {
      return null;
    }
    if (!CUSTOMER_MESSAGE.matcher(value).matches()) {
      throw new InvalidArgumentException(
          "customerMessage must be 4 to 22 characters (letters, digits, spaces).");
    }
    return value;
  }

  /**
   * URL de notification : HTTPS publique, au plus 2048 caractères.
   *
   * @param value URL
   * @return l'URL, ou null
   */
  public static String callbackUrl(String value) {
    if (value == null || value.trim().isEmpty()) {
      return null;
    }
    String url = value.trim();
    boolean valid;
    try {
      URI uri = new URI(url);
      valid =
          "https".equalsIgnoreCase(uri.getScheme())
              && uri.getHost() != null
              && !uri.getHost().isEmpty()
              && url.length() <= 2048;
    } catch (URISyntaxException e) {
      valid = false;
    }
    if (!valid) {
      throw new InvalidArgumentException(
          "callbackUrl must be a public HTTPS URL (at most 2048 characters).");
    }
    return url;
  }

  /**
   * Métadonnées : au plus 20 entrées, valeurs scalaires d'au plus 255 caractères.
   *
   * @param value métadonnées
   * @return copie avec des valeurs chaîne (ou null), ou null
   */
  public static Map<String, String> metadata(Map<String, ?> value) {
    if (value == null) {
      return null;
    }
    if (value.size() > 20) {
      throw new InvalidArgumentException("metadata accepts at most 20 entries.");
    }
    Map<String, String> result = new LinkedHashMap<>();
    for (Map.Entry<String, ?> entry : value.entrySet()) {
      Object item = entry.getValue();
      if (item == null) {
        result.put(entry.getKey(), null);
        continue;
      }
      String text;
      if (item instanceof BigDecimal) {
        text = ((BigDecimal) item).toPlainString();
      } else if (item instanceof CharSequence
          || item instanceof Number
          || item instanceof Boolean) {
        text = item.toString();
      } else {
        throw new InvalidArgumentException(
            "metadata[\"" + entry.getKey() + "\"] must be a string.");
      }
      if (text.length() > 255) {
        throw new InvalidArgumentException(
            "metadata[\"" + entry.getKey() + "\"] must be at most 255 characters.");
      }
      result.put(entry.getKey(), text);
    }
    return result;
  }

  /**
   * Identifiant LabPay (DEP-…, PAY-…, RFD-…).
   *
   * @param value identifiant
   * @param field nom du champ
   * @return l'identifiant nettoyé (non encodé)
   */
  public static String id(String value, String field) {
    String text = value == null ? "" : value.trim();
    if (text.isEmpty() || text.length() > 64) {
      throw new InvalidArgumentException(
          field + " must be a LabPay identifier, e.g. \"DEP-01JCX…\".");
    }
    return text;
  }

  /**
   * Segment de chemin encodé (comme {@code encodeURIComponent}).
   *
   * @param value identifiant validé
   * @return le segment encodé
   */
  public static String pathSegment(String value) {
    return URLEncoder.encode(value, StandardCharsets.UTF_8).replace("+", "%20");
  }

  /**
   * Taille de page : 1 à 100.
   *
   * @param value taille
   * @return la taille, ou null
   */
  public static Integer perPage(Integer value) {
    if (value != null && (value < 1 || value > MAX_PER_PAGE)) {
      throw new InvalidArgumentException(
          "perPage must be an integer between 1 and " + MAX_PER_PAGE + ".");
    }
    return value;
  }

  /**
   * Numéro de page : 1 ou plus.
   *
   * @param value page
   * @return la page, ou null
   */
  public static Integer page(Integer value) {
    if (value != null && value < 1) {
      throw new InvalidArgumentException("page must be an integer, 1 or more.");
    }
    return value;
  }

  /**
   * Date {@code YYYY-MM-DD}.
   *
   * @param value date
   * @param field nom du champ
   * @return la date, ou null
   */
  public static String date(LocalDate value, String field) {
    return value == null ? null : value.toString();
  }

  /**
   * Date {@code YYYY-MM-DD} donnée en texte.
   *
   * @param value date
   * @param field nom du champ
   * @return la date, ou null
   */
  public static String date(String value, String field) {
    if (value == null) {
      return null;
    }
    if (!DATE.matcher(value).matches()) {
      throw new InvalidArgumentException(field + " must be a date in YYYY-MM-DD format.");
    }
    return value;
  }

  /**
   * Valeur parmi une liste.
   *
   * @param value valeur
   * @param allowed valeurs acceptées
   * @param field nom du champ
   * @return la valeur, ou null
   */
  public static String oneOf(String value, Collection<String> allowed, String field) {
    if (value == null) {
      return null;
    }
    if (!allowed.contains(value)) {
      throw new InvalidArgumentException(
          field + " must be one of: " + String.join(", ", allowed) + ".");
    }
    return value;
  }
}
