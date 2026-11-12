package cd.labyrinthe.labpay.examples;

import cd.labyrinthe.labpay.LabPayClient;
import cd.labyrinthe.labpay.MobileMoneyParams;
import cd.labyrinthe.labpay.Payment;
import cd.labyrinthe.labpay.webhook.WebhookEvent;
import java.util.Map;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.event.EventListener;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

/**
 * Spring Boot application: {@code LabPayClient} bean and signed notifications from the starter.
 *
 * <pre>
 * LABPAY_API_KEY=test_… LABPAY_WEBHOOK_SECRET=whsec_… java …SpringBootExample
 * </pre>
 *
 * <p>{@code POST /pay?phone=0813456789} collects 1 000 CDF; the final status arrives on {@code POST
 * /webhooks/labpay}, verified by the starter and published as a {@link WebhookEvent}.
 */
@SpringBootApplication
@RestController
public class SpringBootExample {

  private final LabPayClient labpay;

  /**
   * Creates the controller.
   *
   * @param labpay the auto-configured client
   */
  public SpringBootExample(LabPayClient labpay) {
    this.labpay = labpay;
  }

  /**
   * Runs the example.
   *
   * @param args Spring Boot arguments
   */
  public static void main(String[] args) {
    SpringApplication.run(SpringBootExample.class, args);
  }

  /**
   * Collects 1 000 CDF.
   *
   * @param phone the customer's number
   * @param order your order number (the reference)
   * @return the payment id and status
   */
  @PostMapping("/pay")
  public Map<String, String> pay(@RequestParam String phone, @RequestParam String order) {
    Payment payment =
        labpay
            .deposits()
            .create(
                MobileMoneyParams.builder()
                    .amount("1000")
                    .currency("CDF")
                    .phone(phone)
                    .reference("ORDER-" + order)
                    .callbackUrl("https://shop.example/webhooks/labpay")
                    .build());
    return Map.of("id", payment.id(), "status", payment.status().value());
  }

  /**
   * Called for every verified notification. Answer fast; deduplicate on {@code payment().id()}.
   *
   * @param event the notification
   */
  @EventListener
  public void onNotification(WebhookEvent event) {
    if (event.isPaymentCompleted()) {
      System.out.println("Order paid: " + event.payment().reference());
    }
  }
}
