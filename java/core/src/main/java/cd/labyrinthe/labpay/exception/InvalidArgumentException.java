package cd.labyrinthe.labpay.exception;

/** Invalid input, detected by the SDK before any request ({@code INVALID_ARGUMENT}). */
public class InvalidArgumentException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message
   */
  public InvalidArgumentException(String message) {
    super(message, "INVALID_ARGUMENT", 0);
  }
}
