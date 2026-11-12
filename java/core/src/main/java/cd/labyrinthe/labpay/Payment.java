package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.Map;

/**
 * A V2 payment: deposit ({@code DEP-…}), payout ({@code PAY-…}) or refund ({@code RFD-…}). Same
 * shape in API responses and in notifications. Immutable.
 *
 * <p>Amounts are strings with 2 decimals ({@code "1500.00"}), never floating-point numbers: use
 * {@link #amountValue()} for an exact {@link BigDecimal}. Nullable components are documented as
 * such.
 *
 * @param id LabPay identifier: {@code DEP-…}, {@code PAY-…} or {@code RFD-…}
 * @param reference your reference (unique per token and mode), or null
 * @param operation kind of payment
 * @param livemode false for a test payment (sandbox)
 * @param status public status; rely on it, not on {@code gatewayStatus}
 * @param gatewayStatus raw aggregator status, informational (e.g. {@code ACCEPTED}, {@code
 *     ENQUEUED}), or null
 * @param amount deposit: credited to you; payout: received by the recipient; refund: refunded
 * @param fee LabPay fees, or null
 * @param totalAmount deposit: paid by the customer; payout: debited from your balance; or null
 * @param currency {@code CDF} or {@code USD}
 * @param country ISO 3166-1 alpha-3 country of the number (e.g. {@code COD}), or null
 * @param phone phone number in the local format of the country, or null
 * @param provider provider code (e.g. {@code VODACOM_MPESA_COD}), or null
 * @param providerTransactionId provider reference (the one in the customer's SMS), or null
 * @param failure why the payment failed, or null
 * @param metadata your metadata, or null
 * @param createdAt creation date, or null
 * @param completedAt final status date, null while pending
 * @param refundedAmount deposits only: amount already refunded or being refunded, or null
 * @param refundedPaymentId refunds only: id of the refunded deposit, or null
 * @param raw the payment as returned by the API
 */
public record Payment(
    String id,
    String reference,
    Operation operation,
    boolean livemode,
    PaymentStatus status,
    String gatewayStatus,
    String amount,
    String fee,
    String totalAmount,
    String currency,
    String country,
    String phone,
    String provider,
    String providerTransactionId,
    PaymentFailure failure,
    Map<String, String> metadata,
    Instant createdAt,
    Instant completedAt,
    String refundedAmount,
    String refundedPaymentId,
    Map<String, Object> raw) {

  /**
   * Reads a payment from decoded JSON (an API response {@code data} or a notification {@code
   * payment}).
   *
   * @param value the decoded JSON object
   * @return the payment
   * @throws cd.labyrinthe.labpay.exception.UnexpectedResponseException when a required field is
   *     missing
   */
  public static Payment fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "payment");
    Object rawFailure = data.get("failure");
    PaymentFailure failure = null;
    if (rawFailure != null) {
      Map<String, Object> f = Data.object(rawFailure, "failure");
      failure = new PaymentFailure(Data.string(f, "code"), Data.nullableString(f, "message"));
    }
    return new Payment(
        Data.string(data, "id"),
        Data.nullableString(data, "reference"),
        Operation.fromApi(Data.nullableString(data, "operation")),
        Data.bool(data, "livemode", true),
        PaymentStatus.fromApi(Data.nullableString(data, "status")),
        Data.nullableString(data, "gateway_status"),
        Data.string(data, "amount"),
        Data.nullableString(data, "fee"),
        Data.nullableString(data, "total_amount"),
        Data.string(data, "currency"),
        Data.nullableString(data, "country"),
        Data.nullableString(data, "phone"),
        Data.nullableString(data, "provider"),
        Data.nullableString(data, "provider_transaction_id"),
        failure,
        Data.nullableStringMap(data, "metadata"),
        Data.nullableInstant(data, "created_at"),
        Data.nullableInstant(data, "completed_at"),
        Data.nullableString(data, "refunded_amount"),
        Data.nullableString(data, "refunded_payment_id"),
        data);
  }

  /**
   * Waiting for the customer or the provider.
   *
   * @return true when {@code pending}
   */
  public boolean isPending() {
    return status == PaymentStatus.PENDING;
  }

  /**
   * Deposit: your balance is credited. Payout / refund: the money has left. Deliver only on this
   * status.
   *
   * @return true when {@code completed}
   */
  public boolean isCompleted() {
    return status == PaymentStatus.COMPLETED;
  }

  /**
   * Failed or cancelled; see {@link #failure()}. Payout / refund: your balance is credited back.
   *
   * @return true when {@code failed}
   */
  public boolean isFailed() {
    return status == PaymentStatus.FAILED;
  }

  /**
   * {@code completed} or {@code failed}: the payment will no longer change.
   *
   * @return true when final
   */
  public boolean isFinal() {
    return isCompleted() || isFailed();
  }

  /**
   * A payout queued by the provider ({@code gateway_status: ENQUEUED}): it can be cancelled.
   *
   * @return true when queued
   */
  public boolean isQueued() {
    return isPending() && "ENQUEUED".equals(gatewayStatus);
  }

  /**
   * A sandbox payment (test key).
   *
   * @return true when {@code livemode} is false
   */
  public boolean isTest() {
    return !livemode;
  }

  /**
   * The amount as an exact decimal.
   *
   * @return e.g. {@code 1500.00}
   */
  public BigDecimal amountValue() {
    return new BigDecimal(amount);
  }

  /** The phone number and the metadata are not shown. */
  @Override
  public String toString() {
    return "Payment(id="
        + id
        + ", reference="
        + reference
        + ", operation="
        + operation
        + ", status="
        + status
        + ", amount="
        + amount
        + " "
        + currency
        + ", livemode="
        + livemode
        + ")";
  }
}
