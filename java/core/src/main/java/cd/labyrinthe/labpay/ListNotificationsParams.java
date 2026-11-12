package cd.labyrinthe.labpay;

/** Filters of {@code GET /V2/notifications}. Immutable. */
public final class ListNotificationsParams {

  private static final ListNotificationsParams NONE = builder().build();

  private final String paymentId;
  private final NotificationStatus status;
  private final Integer perPage;
  private final Integer page;

  private ListNotificationsParams(Builder builder) {
    this.paymentId = builder.paymentId;
    this.status = builder.status;
    this.perPage = builder.perPage;
    this.page = builder.page;
  }

  /**
   * A new builder.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * No filter.
   *
   * @return the parameters
   */
  public static ListNotificationsParams none() {
    return NONE;
  }

  /**
   * Payment filter, or null.
   *
   * @return the payment id
   */
  public String paymentId() {
    return paymentId;
  }

  /**
   * Status filter, or null.
   *
   * @return the status
   */
  public NotificationStatus status() {
    return status;
  }

  /**
   * Page size, or null.
   *
   * @return the size
   */
  public Integer perPage() {
    return perPage;
  }

  /**
   * Page number, or null.
   *
   * @return the page
   */
  public Integer page() {
    return page;
  }

  /** Builder of {@link ListNotificationsParams}. Not thread-safe. */
  public static final class Builder {
    private String paymentId;
    private NotificationStatus status;
    private Integer perPage;
    private Integer page;

    private Builder() {}

    /**
     * Only the notifications of this payment.
     *
     * @param paymentId e.g. {@code DEP-01JCX…}
     * @return this builder
     */
    public Builder paymentId(String paymentId) {
      this.paymentId = paymentId;
      return this;
    }

    /**
     * Only the notifications with this status.
     *
     * @param status the status (not {@code UNKNOWN})
     * @return this builder
     */
    public Builder status(NotificationStatus status) {
      this.status = status;
      return this;
    }

    /**
     * Page size.
     *
     * @param perPage 1 to 100
     * @return this builder
     */
    public Builder perPage(Integer perPage) {
      this.perPage = perPage;
      return this;
    }

    /**
     * Page number.
     *
     * @param page 1 or more
     * @return this builder
     */
    public Builder page(Integer page) {
      this.page = page;
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     */
    public ListNotificationsParams build() {
      return new ListNotificationsParams(this);
    }
  }
}
