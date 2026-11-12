package cd.labyrinthe.labpay.exception;

import java.util.List;
import java.util.Map;

/**
 * 429 {@code DAILY_LIMIT_REACHED}: token not enabled for production yet. Never retried
 * automatically.
 */
public class DailyLimitReachedException extends RateLimitException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception (normally done by the SDK).
   *
   * @param message human-readable message (in French when it comes from the API)
   * @param code stable machine-readable code
   * @param statusCode HTTP status
   * @param errors per-field messages
   * @param headers response headers (lower-case names)
   */
  public DailyLimitReachedException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode, errors, headers);
  }
}
