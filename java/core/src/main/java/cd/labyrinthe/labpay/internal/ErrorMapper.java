package cd.labyrinthe.labpay.internal;

import cd.labyrinthe.labpay.exception.AmountOutOfRangeException;
import cd.labyrinthe.labpay.exception.ApiException;
import cd.labyrinthe.labpay.exception.AuthenticationException;
import cd.labyrinthe.labpay.exception.ConflictException;
import cd.labyrinthe.labpay.exception.DailyLimitReachedException;
import cd.labyrinthe.labpay.exception.InsufficientBalanceException;
import cd.labyrinthe.labpay.exception.InvalidPhoneNumberException;
import cd.labyrinthe.labpay.exception.InvalidRequestException;
import cd.labyrinthe.labpay.exception.NotFoundException;
import cd.labyrinthe.labpay.exception.PermissionException;
import cd.labyrinthe.labpay.exception.RateLimitException;
import cd.labyrinthe.labpay.exception.ServiceUnavailableException;
import cd.labyrinthe.labpay.exception.ValidationException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Convertit une réponse d'erreur en exception typée : par code d'abord, puis par statut HTTP, sinon
 * {@link ApiException}. Même table que {@code node/src/errors.ts}. Usage interne.
 */
public final class ErrorMapper {

  /** Code donné par le SDK à un 429 du limiteur (l'API n'envoie pas de code). */
  public static final String TOO_MANY_REQUESTS = "TOO_MANY_REQUESTS";

  /** Code donné par le SDK quand l'API n'a envoyé aucun code. */
  public static final String HTTP_ERROR = "HTTP_ERROR";

  /** Fabrique d'une exception typée. */
  @FunctionalInterface
  private interface Factory {
    ApiException create(
        String message,
        String code,
        int status,
        Map<String, List<String>> errors,
        Map<String, String> headers);
  }

  private static final Map<String, Factory> BY_CODE = byCode();

  private ErrorMapper() {}

  private static void putAll(Map<String, Factory> map, Factory factory, String... codes) {
    for (String code : codes) {
      map.put(code, factory);
    }
  }

  private static Map<String, Factory> byCode() {
    Map<String, Factory> map = new LinkedHashMap<>();
    putAll(map, AuthenticationException::new, "UNAUTHENTICATED");
    putAll(
        map,
        PermissionException::new,
        "TOKEN_BLOCKED",
        "MERCHANT_INACTIVE",
        "KYC_REQUIRED",
        "LIVE_TOKEN_REQUIRED");
    putAll(
        map,
        NotFoundException::new,
        "PAYMENT_NOT_FOUND",
        "DEPOSIT_NOT_FOUND",
        "NOTIFICATION_NOT_FOUND");
    putAll(
        map,
        ConflictException::new,
        "NOT_A_PAYOUT",
        "NOT_CANCELLABLE",
        "DEPOSIT_NOT_COMPLETED",
        "REFUND_IN_PROGRESS",
        "DEPOSIT_ALREADY_REFUNDED");
    putAll(map, ValidationException::new, "VALIDATION_ERROR");
    putAll(map, InvalidPhoneNumberException::new, "INVALID_PHONE_NUMBER");
    putAll(
        map,
        InvalidRequestException::new,
        "UNSUPPORTED_COUNTRY",
        "INVALID_CURRENCY",
        "UNSUPPORTED_PROVIDER",
        "INVALID_AMOUNT");
    putAll(
        map,
        AmountOutOfRangeException::new,
        "AMOUNT_TOO_SMALL",
        "AMOUNT_TOO_LARGE",
        "AMOUNT_OUT_OF_BOUNDS");
    putAll(map, InsufficientBalanceException::new, "INSUFFICIENT_BALANCE");
    putAll(map, DailyLimitReachedException::new, "DAILY_LIMIT_REACHED");
    putAll(
        map,
        ServiceUnavailableException::new,
        "MAINTENANCE",
        "API_DISABLED",
        "DEPOSITS_DISABLED",
        "WITHDRAWALS_DISABLED",
        "PROVIDER_TEMPORARILY_UNAVAILABLE",
        "GATEWAY_UNAVAILABLE",
        "V2_DISABLED",
        "TEST_MODE_DISABLED",
        "API_VERSION_DISABLED",
        "FEATURE_DISABLED");
    return Collections.unmodifiableMap(map);
  }

  private static Factory forStatus(int status) {
    if (status == 401) {
      return AuthenticationException::new;
    }
    if (status == 403) {
      return PermissionException::new;
    }
    if (status == 404) {
      return NotFoundException::new;
    }
    if (status == 409) {
      return ConflictException::new;
    }
    if (status == 422) {
      return InvalidRequestException::new;
    }
    if (status == 429) {
      return RateLimitException::new;
    }
    if (status >= 500) {
      return ServiceUnavailableException::new;
    }
    return ApiException::new;
  }

  /**
   * Codes de l'API qui ont une exception dédiée (test de contrat).
   *
   * @return les codes
   */
  public static Set<String> knownCodes() {
    return BY_CODE.keySet();
  }

  /**
   * L'exception correspondant à une réponse d'erreur.
   *
   * @param status statut HTTP
   * @param body corps décodé (ou null s'il n'est pas du JSON)
   * @param headers en-têtes (noms en minuscules)
   * @return l'exception (à lever)
   */
  public static ApiException fromResponse(int status, Object body, Map<String, String> headers) {
    Map<String, Object> object = Data.lenientObject(body);
    Map<String, List<String>> errors = fieldErrors(object.get("errors"));
    Object rawCode = object.get("code");
    String code =
        rawCode instanceof String && !((String) rawCode).isEmpty() ? (String) rawCode : null;
    if (code == null) {
      if (status == 429) {
        code = TOO_MANY_REQUESTS;
      } else if (status == 422 && !errors.isEmpty()) {
        code = "VALIDATION_ERROR";
      } else {
        code = HTTP_ERROR;
      }
    }
    Object rawMessage = object.get("message");
    String message =
        rawMessage instanceof String && !((String) rawMessage).isEmpty()
            ? (String) rawMessage
            : "The LabPay API returned HTTP " + status + ".";
    Factory factory = BY_CODE.getOrDefault(code, forStatus(status));
    return factory.create(message, code, status, errors, headers);
  }

  private static Map<String, List<String>> fieldErrors(Object value) {
    if (!(value instanceof Map)) {
      return Collections.emptyMap();
    }
    Map<String, List<String>> result = new LinkedHashMap<>();
    for (Map.Entry<?, ?> entry : ((Map<?, ?>) value).entrySet()) {
      List<String> messages = new ArrayList<>();
      Object item = entry.getValue();
      List<?> list = item instanceof List ? (List<?>) item : Collections.singletonList(item);
      for (Object message : list) {
        if (message instanceof String) {
          messages.add((String) message);
        }
      }
      result.put(String.valueOf(entry.getKey()), messages);
    }
    return result;
  }
}
