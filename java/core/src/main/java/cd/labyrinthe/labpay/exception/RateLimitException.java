package cd.labyrinthe.labpay.exception;

import java.util.List;
import java.util.Map;

/** 429: too many requests for this token (120 per minute); see {@link #retryAfter()}. */
public class RateLimitException extends ApiException {

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
  public RateLimitException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode, errors, headers);
  }
}
