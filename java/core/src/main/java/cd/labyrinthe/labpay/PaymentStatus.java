package cd.labyrinthe.labpay;

/**
 * Public status of a payment. {@code completed} and {@code failed} are final.
 *
 * <p>{@link #UNKNOWN} stands for a value this SDK version does not know yet (the raw value stays
 * available in the {@code raw} map of the model).
 */
public enum PaymentStatus {
  /** Waiting for the customer or the provider. */
  PENDING("pending"),

  /** Final: deposit credited to you, payout or refund sent. Deliver only on this status. */
  COMPLETED("completed"),

  /** Final: refused, expired or cancelled; see {@link Payment#failure()}. */
  FAILED("failed"),

  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  PaymentStatus(String value) {
    this.value = value;
  }

  /**
   * The value sent by the API.
   *
   * @return e.g. {@code "pending"}
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
  public static PaymentStatus fromApi(String value) {
    for (PaymentStatus item : values()) {
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
