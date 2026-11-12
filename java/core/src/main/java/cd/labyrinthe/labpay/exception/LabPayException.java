package cd.labyrinthe.labpay.exception;

/**
 * Base class of every exception thrown by the SDK: catch it to handle any LabPay failure in one
 * place.
 *
 * <p>Always switch on {@link #code()} (stable) or on the class, never on the message: API messages
 * are in French, for humans, and may change. Exceptions never contain the API key.
 */
public class LabPayException extends RuntimeException {

  private static final long serialVersionUID = 1L;

  private final String code;
  private final int statusCode;

  /**
   * Creates an exception.
   *
   * @param message human-readable message
   * @param code stable machine-readable code, e.g. {@code "INSUFFICIENT_BALANCE"} or {@code
   *     "TIMEOUT"}
   * @param statusCode HTTP status, or {@code 0} without a response
   */
  public LabPayException(String message, String code, int statusCode) {
    super(message, null, true, true);
    this.code = code;
    this.statusCode = statusCode;
  }

  /**
   * Stable machine-readable code: an API error code ({@code "VALIDATION_ERROR"}…) or an SDK code
   * ({@code "CONNECTION_ERROR"}, {@code "TIMEOUT"}, {@code "INVALID_ARGUMENT"}…).
   *
   * @return the code
   */
  public String code() {
    return code;
  }

  /**
   * HTTP status of the response.
   *
   * @return the status, or {@code 0} when no response was received (network error, timeout, local
   *     validation, webhook verification…)
   */
  public int statusCode() {
    return statusCode;
  }

  @Override
  public String toString() {
    return getClass().getSimpleName()
        + "(code="
        + code
        + ", statusCode="
        + statusCode
        + "): "
        + getMessage();
  }
}
