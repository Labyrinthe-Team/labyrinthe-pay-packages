package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * History of the notifications sent to your {@code callback_url} ({@code client.notifications()}).
 */
public final class NotificationService extends Resource {

  private static final List<String> STATUSES = List.of("delivered", "pending", "abandoned");

  NotificationService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Notifications of the token's payments (mode of the key), newest first.
   *
   * @param params filters and page
   * @return one page
   */
  public Page<Notification> list(ListNotificationsParams params) {
    ListNotificationsParams p = params == null ? ListNotificationsParams.none() : params;
    Map<String, Object> query = new LinkedHashMap<>();
    query.put("payment_id", p.paymentId() == null ? null : Input.id(p.paymentId(), "paymentId"));
    query.put(
        "status", p.status() == null ? null : Input.oneOf(p.status().value(), STATUSES, "status"));
    query.put("per_page", Input.perPage(p.perPage()));
    query.put("page", Input.page(p.page()));
    return Page.fromJson(
        transport.request("GET", "/notifications", RetryMode.SAFE, query, null, false).body(),
        Notification::fromJson);
  }

  /**
   * The first page of notifications.
   *
   * @return one page
   */
  public Page<Notification> list() {
    return list(ListNotificationsParams.none());
  }

  /**
   * Resends a notification (a new one is created and delivered). Never retried automatically after
   * a network error or a 5xx.
   *
   * @param id notification id
   * @return the new notification
   */
  public Notification resend(long id) {
    if (id < 1) {
      throw new InvalidArgumentException("id must be a positive integer.");
    }
    return Notification.fromJson(
        transport
            .request(
                "POST",
                "/notifications/" + id + "/resend",
                RetryMode.THROTTLED_ONLY,
                null,
                null,
                false)
            .data());
  }
}
