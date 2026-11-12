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
