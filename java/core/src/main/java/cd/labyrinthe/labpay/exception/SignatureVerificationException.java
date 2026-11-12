package cd.labyrinthe.labpay.exception;

/** Wrong or missing notification signature, or timestamp out of tolerance: answer 400. */
public class SignatureVerificationException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message
   */
  public SignatureVerificationException(String message) {
    super(message, "SIGNATURE_VERIFICATION_FAILED", 0);
  }
}
