package cd.labyrinthe.labpay;

/**
 * Delivery status of a notification.
 *
 * <p>{@link #UNKNOWN} stands for a value this SDK version does not know yet (the raw value stays
 * available in the {@code raw} map of the model).
 */
public enum NotificationStatus {
  /** Your endpoint answered 2xx. */
  DELIVERED("delivered"),

  /** Waiting for (another) attempt. */
  PENDING("pending"),

  /** Every attempt failed; it can be resent. */
  ABANDONED("abandoned"),

  /** A value this SDK version does not know yet. */
  UNKNOWN("unknown");

  private final String value;

  NotificationStatus(String value) {
    this.value = value;
  }

  /**
   * The value sent by the API.
   *
   * @return e.g. {@code "delivered"}
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
  public static NotificationStatus fromApi(String value) {
    for (NotificationStatus item : values()) {
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
