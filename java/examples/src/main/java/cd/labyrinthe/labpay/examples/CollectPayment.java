package cd.labyrinthe.labpay.examples;

import cd.labyrinthe.labpay.LabPayClient;
import cd.labyrinthe.labpay.MobileMoneyParams;
import cd.labyrinthe.labpay.Payment;
import cd.labyrinthe.labpay.exception.ConnectionException;
import cd.labyrinthe.labpay.exception.InvalidRequestException;

/**
 * Collects 1 000 CDF from a customer (test key: sandbox, no real money).
 *
 * <pre>
 * LABPAY_API_KEY=test_… java cd.labyrinthe.labpay.examples.CollectPayment
 * </pre>
 */
public final class CollectPayment {

  private CollectPayment() {}

  /**
   * Runs the example.
   *
   * @param args unused
   */
  public static void main(String[] args) {
    LabPayClient labpay = LabPayClient.fromEnv();
    MobileMoneyParams params =
        MobileMoneyParams.builder()
            .amount("1000") // net amount credited to you; the customer pays it + fees
            .currency("CDF")
            .phone("0813456789") // sandbox number: success
            .reference("ORDER-42") // unique per token: a resend returns the same payment
            .callbackUrl("https://shop.example/webhooks/labpay")
            .metadata("orderId", 42)
            .build();
    try {
      Payment payment = labpay.deposits().create(params);
      if (payment.isFailed()) {
        // Refused at initiation (e.g. payer limit): nothing to collect.
        System.out.println("Refused: " + payment.failure());
        return;
      }
      // "pending": the customer confirms with their PIN; the final status arrives on callbackUrl.
      System.out.println(payment.id() + " " + payment.status());
    } catch (InvalidRequestException e) {
      System.out.println("Invalid request (" + e.code() + "): " + e.getMessage());
    } catch (ConnectionException e) {
      // The payment may exist: resend the same reference, or look it up.
      System.out.println(labpay.payments().findByReference("ORDER-42").map(Payment::status));
    }
  }
}
