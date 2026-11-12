package cd.labyrinthe.labpay;

/**
 * Kind of payment.
 *
 * <p>{@link #UNKNOWN} stands for a value this SDK version does not know yet (the raw value stays
 * available in the {@code raw} map of the model).
 */
public enum Operation {
  /** Collection from a customer ({@code DEP-…}). */
  DEPOSIT("deposit"),

  /** Money sent to a wallet ({@code PAY-…}). */
  PAYOUT("payout"),

  /** Refund of a deposit ({@code RFD-…}). */
  REFUND("refund"),

  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  Operation(String value) {
    this.value = value;
  }

  /**
   * The value sent by the API.
   *
   * @return e.g. {@code "deposit"}
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
  public static Operation fromApi(String value) {
    for (Operation item : values()) {
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
