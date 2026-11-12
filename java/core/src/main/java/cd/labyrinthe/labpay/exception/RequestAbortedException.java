package cd.labyrinthe.labpay.exception;

/**
 * The calling thread was interrupted while the SDK was waiting ({@code REQUEST_ABORTED}); the
 * interrupt flag is kept.
 */
public class RequestAbortedException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message
   */
  public RequestAbortedException(String message) {
    super(message, "REQUEST_ABORTED", 0);
  }
}
