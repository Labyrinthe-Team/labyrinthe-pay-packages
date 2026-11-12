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
