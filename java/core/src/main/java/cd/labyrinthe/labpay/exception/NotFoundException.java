package cd.labyrinthe.labpay.exception;

import java.util.List;
import java.util.Map;

/**
 * 404: {@code PAYMENT_NOT_FOUND}, {@code DEPOSIT_NOT_FOUND} or {@code NOTIFICATION_NOT_FOUND} (for
 * this token and mode).
 */
public class NotFoundException extends ApiException {

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
  public NotFoundException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode, errors, headers);
  }
}
