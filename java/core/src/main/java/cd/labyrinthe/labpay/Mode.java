package cd.labyrinthe.labpay;

/**
 * Mode of the key used: live token or test key ({@code test_…}).
 *
 * <p>{@link #UNKNOWN} stands for a value this SDK version does not know yet (the raw value stays
 * available in the {@code raw} map of the model).
 */
public enum Mode {
  /** Production: real money. */
  LIVE("live"),

  /** Sandbox: no real money, separate balances and payments. */
  TEST("test"),

  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  Mode(String value) {
    this.value = value;
  }

  /**
   * The value sent by the API.
   *
   * @return e.g. {@code "live"}
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
  public static Mode fromApi(String value) {
    for (Mode item : values()) {
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
