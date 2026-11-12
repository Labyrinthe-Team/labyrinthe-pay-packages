package cd.labyrinthe.labpay.exception;

/** The API answered something the SDK cannot read ({@code UNEXPECTED_RESPONSE}). */
public class UnexpectedResponseException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message
   */
  public UnexpectedResponseException(String message) {
    super(message, "UNEXPECTED_RESPONSE", 0);
  }
}
