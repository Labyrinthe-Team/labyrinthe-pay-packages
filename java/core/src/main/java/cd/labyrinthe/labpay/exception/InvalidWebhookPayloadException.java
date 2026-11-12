package cd.labyrinthe.labpay.exception;

/** Correctly signed body that is not a valid notification: answer 400. */
public class InvalidWebhookPayloadException extends LabPayException {

  private static final long serialVersionUID = 1L;

  /**
   * Creates the exception.
   *
   * @param message human-readable message
   */
  public InvalidWebhookPayloadException(String message) {
    super(message, "INVALID_WEBHOOK_PAYLOAD", 0);
  }
}
