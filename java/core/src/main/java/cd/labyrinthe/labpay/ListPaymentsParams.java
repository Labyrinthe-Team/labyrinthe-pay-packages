package cd.labyrinthe.labpay;

import java.time.LocalDate;

/**
 * Filters of {@code GET /V2/payments}. Immutable.
 *
 * <pre>{@code
 * ListPaymentsParams filters =
 *     ListPaymentsParams.builder()
 *         .status(PaymentStatus.COMPLETED)
 *         .operation(Operation.DEPOSIT)
 *         .from(LocalDate.of(2026, 11, 1))
 *         .build();
 * }</pre>
 */
public final class ListPaymentsParams {

  private static final ListPaymentsParams NONE = builder().build();

  private final PaymentStatus status;
  private final Operation operation;
  private final String currency;
  private final String country;
  private final String reference;
  private final LocalDate from;
  private final LocalDate to;
  private final Integer perPage;
  private final Integer page;

  private ListPaymentsParams(Builder builder) {
    this.status = builder.status;
    this.operation = builder.operation;
    this.currency = builder.currency;
    this.country = builder.country;
    this.reference = builder.reference;
    this.from = builder.from;
    this.to = builder.to;
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
  public static ListPaymentsParams none() {
    return NONE;
  }

  /**
   * A builder with the same filters.
   *
   * @return the builder
   */
  public Builder toBuilder() {
    return builder()
        .status(status)
        .operation(operation)
        .currency(currency)
        .country(country)
        .reference(reference)
        .from(from)
        .to(to)
        .perPage(perPage)
        .page(page);
  }

  /**
   * Status filter, or null.
   *
   * @return the status
   */
  public PaymentStatus status() {
    return status;
  }

  /**
   * Operation filter, or null.
   *
   * @return the operation
   */
  public Operation operation() {
    return operation;
  }

  /**
   * Currency filter, or null.
   *
   * @return the currency
   */
  public String currency() {
    return currency;
  }

  /**
   * Country filter, or null.
   *
   * @return the country
   */
  public String country() {
    return country;
  }

  /**
   * Reference filter, or null.
   *
   * @return the reference
   */
  public String reference() {
    return reference;
  }

  /**
   * Creation date lower bound, or null.
   *
   * @return the date
   */
  public LocalDate from() {
    return from;
  }

  /**
   * Creation date upper bound, or null.
   *
   * @return the date
   */
  public LocalDate to() {
    return to;
  }

  /**
   * Page size, or null (API default: 20).
   *
   * @return the size
   */
  public Integer perPage() {
    return perPage;
  }

  /**
   * Page number, or null (first page).
   *
   * @return the page
   */
  public Integer page() {
    return page;
  }

  /** Builder of {@link ListPaymentsParams}. Not thread-safe. */
  public static final class Builder {
    private PaymentStatus status;
    private Operation operation;
    private String currency;
    private String country;
    private String reference;
    private LocalDate from;
    private LocalDate to;
    private Integer perPage;
    private Integer page;

    private Builder() {}

    /**
     * Only payments with this status.
     *
     * @param status the status (not {@code UNKNOWN})
     * @return this builder
     */
    public Builder status(PaymentStatus status) {
      this.status = status;
      return this;
    }

    /**
     * Only payments of this kind.
     *
     * @param operation the operation (not {@code UNKNOWN})
     * @return this builder
     */
    public Builder operation(Operation operation) {
      this.operation = operation;
      return this;
    }

    /**
     * Only payments in this currency.
     *
     * @param currency e.g. {@code CDF}
     * @return this builder
     */
    public Builder currency(String currency) {
      this.currency = currency;
      return this;
    }

    /**
     * Only payments in this country.
     *
     * @param country ISO alpha-3, e.g. {@code COD}
     * @return this builder
     */
    public Builder country(String country) {
      this.country = country;
      return this;
    }

    /**
     * Only the payment carrying this reference.
     *
     * @param reference your reference
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * Created on or after this date.
     *
     * @param from the date
     * @return this builder
     */
    public Builder from(LocalDate from) {
      this.from = from;
      return this;
    }

    /**
     * Created on or before this date (not before {@code from}).
     *
     * @param to the date
     * @return this builder
     */
    public Builder to(LocalDate to) {
      this.to = to;
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
    public ListPaymentsParams build() {
      return new ListPaymentsParams(this);
    }
  }
}
