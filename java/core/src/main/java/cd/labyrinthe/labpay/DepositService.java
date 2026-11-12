package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Transport;

/** Collect from a customer ({@code client.deposits()}, {@code POST /V2/deposits}). */
public final class DepositService extends Resource {

  DepositService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Asks the customer to pay: they get a PIN prompt on their phone. The returned payment is usually
   * {@code pending}; its final status arrives by notification on {@code callbackUrl} (or read it
   * with {@code payments().get()}). Only deliver an order on {@code completed}.
   *
   * <p>A payment refused at initiation is returned with the {@code failed} status (and {@link
   * Payment#failure()}), not thrown. A {@link cd.labyrinthe.labpay.exception.ConnectionException}
   * does not mean the payment failed: resend with the same {@code reference}.
   *
   * @param params amount, currency, phone…
   * @return the payment
   * @throws cd.labyrinthe.labpay.exception.LabPayException on an invalid argument or an API error
   */
  public Payment create(MobileMoneyParams params) {
    return initiate("/deposits", params);
  }
}
