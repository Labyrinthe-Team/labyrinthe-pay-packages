package cd.labyrinthe.labpay.webhook;

import cd.labyrinthe.labpay.Payment;
import cd.labyrinthe.labpay.WebhookEventType;
import java.time.Instant;
import java.util.Map;

/**
 * A verified notification: {@code {"event", "payment", "sent_at"}}.
 *
 * <p>The same notification can arrive more than once (redelivery): deduplicate on the {@code
 * X-Labyrinthe-Delivery} header or on {@code payment().id()}. Arrival order is not guaranteed: when
 * in doubt, read the payment with {@code payments().get()}.
 *
 * @param event the event name as sent ({@code payment.completed}, {@code payment.failed}, or a
 *     newer one)
 * @param payment the payment, same shape as in API responses
 * @param sentAt sending date, or null
 * @param timestamp signed Unix timestamp ({@code t} of {@code X-Labyrinthe-Signature})
 * @param raw the decoded body
 */
public record WebhookEvent(
    String event, Payment payment, Instant sentAt, long timestamp, Map<String, Object> raw) {

  /**
   * The event as a constant.
   *
   * @return the type, {@link WebhookEventType#UNKNOWN} for a newer event
   */
  public WebhookEventType type() {
    return WebhookEventType.fromApi(event);
  }

  /**
   * The payment reached {@code completed}: deliver the order (once).
   *
   * @return true for {@code payment.completed}
   */
  public boolean isPaymentCompleted() {
    return type() == WebhookEventType.PAYMENT_COMPLETED;
  }

  /**
   * The payment reached {@code failed}.
   *
   * @return true for {@code payment.failed}
   */
  public boolean isPaymentFailed() {
    return type() == WebhookEventType.PAYMENT_FAILED;
  }
}
