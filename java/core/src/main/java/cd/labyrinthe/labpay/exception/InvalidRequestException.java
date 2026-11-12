package cd.labyrinthe.labpay.exception;

import java.util.List;
import java.util.Map;

/**
 * 422 without a payment: refused before any payment was created ({@code INVALID_CURRENCY}, {@code
 * UNSUPPORTED_COUNTRY}, {@code UNSUPPORTED_PROVIDER}, {@code INVALID_AMOUNT}…). A payment refused
 * by the provider is not an exception: it is returned with the {@code failed} status.
 */
public class InvalidRequestException extends ApiException {

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
  public InvalidRequestException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode, errors, headers);
  }
}
