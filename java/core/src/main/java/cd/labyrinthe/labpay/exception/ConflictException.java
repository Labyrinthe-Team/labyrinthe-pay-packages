package cd.labyrinthe.labpay.exception;

import java.util.List;
import java.util.Map;

/**
 * 409: {@code NOT_A_PAYOUT}, {@code NOT_CANCELLABLE}, {@code DEPOSIT_NOT_COMPLETED}, {@code
 * REFUND_IN_PROGRESS}, {@code DEPOSIT_ALREADY_REFUNDED}.
 */
public class ConflictException extends ApiException {

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
  public ConflictException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode, errors, headers);
  }
}
