package cd.labyrinthe.labpay;

/**
 * Event of a notification ({@code X-Labyrinthe-Event} header and {@code event} field).
 *
 * <p>{@link #UNKNOWN} stands for a value this SDK version does not know yet (the raw value stays
 * available in the {@code raw} map of the model).
 */
public enum WebhookEventType {
  /** The payment reached {@code completed}. */
  PAYMENT_COMPLETED("payment.completed"),

  /** The payment reached {@code failed}. */
  PAYMENT_FAILED("payment.failed"),

  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  WebhookEventType(String value) {
    this.value = value;
  }

  /**
   * The value sent by the API.
   *
   * @return e.g. {@code "payment.completed"}
   */
  public String value() {
    return value;
  }

  /**
   * The constant for an API value.
   *
   * @param value the API value (may be null)
   * @return the constant, {@link #UNKNOWN} when not known
   */
  public static WebhookEventType fromApi(String value) {
    for (WebhookEventType item : values()) {
      if (item != UNKNOWN && item.value.equals(value)) {
        return item;
      }
    }
    return UNKNOWN;
  }

  @Override
  public String toString() {
    return value;
  }
}
