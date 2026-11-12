package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.LinkedHashMap;
import java.util.Map;

/** Refund a deposit ({@code client.refunds()}, {@code POST /V2/refunds}). */
public final class RefundService extends Resource {

  RefundService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Refunds all or part of a completed V2 deposit, to the wallet that paid. Fees are not refunded;
   * one refund in progress at a time per deposit.
   *
   * @param params deposit id, optional amount…
   * @return the refund ({@code RFD-…}), usually {@code pending}
   */
  public Payment create(RefundParams params) {
    if (params == null) {
      throw new InvalidArgumentException("params are required.");
    }
    Map<String, Object> body = new LinkedHashMap<>();
    body.put("payment_id", Input.id(params.paymentId(), "paymentId"));
    putIfPresent(
        body, "amount", params.amount() == null ? null : Input.amount(params.amount(), "amount"));
    putIfPresent(body, "callback_url", Input.callbackUrl(params.callbackUrl()));
    putIfPresent(body, "metadata", Input.metadata(params.metadata()));
    String reference = reference(params.reference());
    putIfPresent(body, "reference", reference);
    Transport.Response response =
        transport.request(
            "POST",
            "/refunds",
            reference == null ? RetryMode.THROTTLED_ONLY : RetryMode.SAFE,
            null,
            body,
            true);
    return Payment.fromJson(response.data());
  }

  /**
   * Refunds everything left to refund on a deposit.
   *
   * @param paymentId id of the deposit ({@code DEP-…})
   * @return the refund
   */
  public Payment create(String paymentId) {
    return create(RefundParams.of(paymentId));
  }
}
