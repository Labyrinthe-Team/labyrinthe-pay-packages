package cd.labyrinthe.labpay;

import java.math.BigDecimal;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Parameters of a refund ({@code POST /V2/refunds}). Immutable.
 *
 * <pre>{@code
 * RefundParams params =
 *     RefundParams.builder().paymentId("DEP-01JCX…").amount("300").reference("REFUND-42-1").build();
 * }</pre>
 */
public final class RefundParams {

  private final String paymentId;
  private final Object amount;
  private final String reference;
  private final String callbackUrl;
  private final Map<String, Object> metadata;

  private RefundParams(Builder builder) {
    this.paymentId = builder.paymentId;
    this.amount = builder.amount;
    this.reference = builder.reference;
    this.callbackUrl = builder.callbackUrl;
    this.metadata =
        builder.metadata == null
            ? null
            : Collections.unmodifiableMap(new LinkedHashMap<>(builder.metadata));
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
   * Refunds everything left to refund on a deposit.
   *
   * @param paymentId id of the deposit ({@code DEP-…})
   * @return the parameters
   */
  public static RefundParams of(String paymentId) {
    return builder().paymentId(paymentId).build();
  }

  /**
   * The deposit to refund.
   *
   * @return the id
   */
  public String paymentId() {
    return paymentId;
  }

  /**
   * The amount, or null for everything left to refund.
   *
   * @return a {@code String} or a {@code BigDecimal}
   */
  public Object amount() {
    return amount;
  }

  /**
   * Your reference, or null.
   *
   * @return the reference
   */
  public String reference() {
    return reference;
  }

  /**
   * The notification URL, or null.
   *
   * @return the URL
   */
  public String callbackUrl() {
    return callbackUrl;
  }

  /**
   * Your metadata, or null.
   *
   * @return unmodifiable metadata
   */
  public Map<String, Object> metadata() {
    return metadata;
  }

  /** Builder of {@link RefundParams}. Not thread-safe. */
  public static final class Builder {
    private String paymentId;
    private Object amount;
    private String reference;
    private String callbackUrl;
    private Map<String, Object> metadata;

    private Builder() {}

    /**
     * Id of the completed V2 deposit to refund.
     *
     * @param paymentId e.g. {@code DEP-01JCX…}
     * @return this builder
     */
    public Builder paymentId(String paymentId) {
      this.paymentId = paymentId;
      return this;
    }

    /**
     * Amount to refund (default: everything left to refund).
     *
     * @param amount e.g. {@code "300"}
     * @return this builder
     */
    public Builder amount(String amount) {
      this.amount = amount;
      return this;
    }

    /**
     * Amount to refund as an exact decimal.
     *
     * @param amount at most 2 decimals
     * @return this builder
     */
    public Builder amount(BigDecimal amount) {
      this.amount = amount;
      return this;
    }

    /**
     * Your reference (idempotency key); generated when absent unless {@code autoReference(false)}.
     *
     * @param reference 1 to 100 of {@code A-Z a-z 0-9 . _ : -}
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * Public HTTPS URL receiving the final status notification.
     *
     * @param callbackUrl the URL
     * @return this builder
     */
    public Builder callbackUrl(String callbackUrl) {
      this.callbackUrl = callbackUrl;
      return this;
    }

    /**
     * Up to 20 key/value pairs.
     *
     * @param metadata the metadata
     * @return this builder
     */
    public Builder metadata(Map<String, ?> metadata) {
      this.metadata = metadata == null ? null : new LinkedHashMap<>(metadata);
      return this;
    }

    /**
     * Builds the parameters.
     *
     * @return the parameters
     */
    public RefundParams build() {
      return new RefundParams(this);
    }
  }
}
