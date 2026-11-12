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
