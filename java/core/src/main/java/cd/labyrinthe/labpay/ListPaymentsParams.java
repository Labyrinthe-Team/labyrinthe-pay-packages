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

