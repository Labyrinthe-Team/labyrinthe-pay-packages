package cd.labyrinthe.labpay.exception;

/**
 * The API could not be reached or did not answer in time ({@code CONNECTION_ERROR}, {@code
 * TIMEOUT}).
 *
 * <p>For a deposit, payout or refund this does <b>not</b> mean the payment failed: it may have been
 * created. Send the same request again with the same {@code reference}, or look it up with {@code
 * payments().findByReference()}.
 */
public class ConnectionException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message (never contains the key or query values)
   * @param timeout true for {@code TIMEOUT}, false for {@code CONNECTION_ERROR}
   */
  public ConnectionException(String message, boolean timeout) {
    super(message, timeout ? "TIMEOUT" : "CONNECTION_ERROR", 0);
  }

  /**
   * Whether the API did not answer in time.
   *
   * @return true for {@code TIMEOUT}
   */
  public boolean isTimeout() {
    return "TIMEOUT".equals(code());
  }
}
