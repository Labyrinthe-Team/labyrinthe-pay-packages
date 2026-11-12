package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.time.Instant;
import java.util.Map;

/**
 * A notification sent (or to be sent) to your {@code callback_url}.
 *
 * @param id identifier, also the {@code X-Labyrinthe-Delivery} header of the delivery
 * @param event event name, e.g. {@code payment.completed}
 * @param paymentId id of the payment, or null
 * @param url destination URL, or null
 * @param status delivery status
 * @param attempts number of attempts made
 * @param lastStatusCode HTTP status of the last attempt, or null
 * @param lastError error of the last attempt, or null
 * @param nextAttemptAt date of the next attempt, or null
 * @param deliveredAt delivery date, or null
 * @param createdAt creation date, or null
 */
public record Notification(
    long id,
    String event,
    String paymentId,
    String url,
    NotificationStatus status,
    int attempts,
    Integer lastStatusCode,
    String lastError,
    Instant nextAttemptAt,
    Instant deliveredAt,
    Instant createdAt) {

  /**
   * The event as a constant.
   *
   * @return the type, {@link WebhookEventType#UNKNOWN} for a newer event
   */
  public WebhookEventType type() {
    return WebhookEventType.fromApi(event);
  }

  static Notification fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "notification");
    Long id = Data.nullableLong(data, "id");
    if (id == null) {
      throw Data.invalid("id", "an integer");
    }
    Integer attempts = Data.nullableInt(data, "attempts");
    String event = Data.nullableString(data, "event");
    return new Notification(
        id,
        event == null ? "unknown" : event,
        Data.nullableString(data, "payment_id"),
        Data.nullableString(data, "url"),
        NotificationStatus.fromApi(Data.nullableString(data, "status")),
        attempts == null ? 0 : attempts,
        Data.nullableInt(data, "last_status_code"),
        Data.nullableString(data, "last_error"),
        Data.nullableInstant(data, "next_attempt_at"),
        Data.nullableInstant(data, "delivered_at"),
        Data.nullableInstant(data, "created_at"));
  }
}
