package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;

/** Send money to a Mobile Money wallet ({@code client.payouts()}, {@code POST /V2/payouts}). */
public final class PayoutService extends Resource {

  PayoutService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Sends {@code amount} to the number. Your balance is debited immediately with amount + payout
   * fees, and credited back in full if the payout fails.
   *
   * @param params amount, currency, phone…
   * @return the payout, usually {@code pending}
   */
  public Payment create(MobileMoneyParams params) {
    return initiate("/payouts", params);
  }

  /**
   * Cancels a payout still queued by the provider ({@code gatewayStatus: ENQUEUED}, see {@link
   * Payment#isQueued()}). Asynchronous: the payout then moves to {@code failed} and your balance is
   * credited back. Not retried after a network error or a 5xx.
   *
   * @param id payout id ({@code PAY-…})
   * @return the payout
   */
  public Payment cancel(String id) {
    String path = "/payouts/" + Input.pathSegment(Input.id(id, "id")) + "/cancel";
    return Payment.fromJson(
        transport.request("POST", path, RetryMode.THROTTLED_ONLY, null, null, false).data());
  }
}
