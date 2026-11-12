package cd.labyrinthe.labpay.examples;

import cd.labyrinthe.labpay.Balance;
import cd.labyrinthe.labpay.LabPayClient;
import cd.labyrinthe.labpay.MobileMoneyParams;
import cd.labyrinthe.labpay.Payment;
import cd.labyrinthe.labpay.ProviderPrediction;
import cd.labyrinthe.labpay.exception.InsufficientBalanceException;
import cd.labyrinthe.labpay.exception.InvalidPhoneNumberException;
import java.math.BigDecimal;

/**
 * Sends 5 000 CDF to a wallet after checking the number and the balance.
 *
 * <pre>
 * LABPAY_API_KEY=test_… java cd.labyrinthe.labpay.examples.SendPayout
 * </pre>
 */
public final class SendPayout {

  private SendPayout() {}

  /**
   * Runs the example.
   *
   * @param args unused
   */
  public static void main(String[] args) {
    LabPayClient labpay = LabPayClient.fromEnv();
    try {
      ProviderPrediction prediction = labpay.providers().predict("0813456789");
      System.out.println("Provider: " + prediction.provider());

      BigDecimal available =
          labpay.account().balance("CDF").map(Balance::value).orElse(BigDecimal.ZERO);
      System.out.println("CDF balance: " + available.toPlainString());

      Payment payout =
          labpay
              .payouts()
              .create(
                  MobileMoneyParams.builder()
                      .amount(new BigDecimal("5000"))
                      .currency("CDF")
                      .phone("0813456789")
                      .reference("PAYOUT-2026-11-0042")
                      .build());
      System.out.println(payout.id() + " " + payout.status() + ", debited " + payout.totalAmount());
      if (payout.isQueued()) {
        // Provider delayed: the payout can still be cancelled.
        labpay.payouts().cancel(payout.id());
      }
    } catch (InvalidPhoneNumberException e) {
      System.out.println("Invalid number: " + e.getMessage());
    } catch (InsufficientBalanceException e) {
      System.out.println("Insufficient balance: nothing was sent.");
    }
  }
}
