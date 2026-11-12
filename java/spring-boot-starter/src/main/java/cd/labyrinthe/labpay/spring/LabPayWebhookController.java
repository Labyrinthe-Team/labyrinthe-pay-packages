package cd.labyrinthe.labpay.spring;

import cd.labyrinthe.labpay.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.labpay.exception.SignatureVerificationException;
import cd.labyrinthe.labpay.webhook.WebhookEvent;
import cd.labyrinthe.labpay.webhook.Webhooks;
import java.time.Clock;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.Objects;
import org.springframework.context.ApplicationEventPublisher;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RestController;

/**
 * Signed notification endpoint: {@code POST ${labpay.webhook.path}} (default {@code
 * /webhooks/labpay}), to use as {@code callback_url}.
 *
 * <p>The signature is verified on the raw body; a verified {@link WebhookEvent} is published with
 * {@link ApplicationEventPublisher#publishEvent(Object)}:
 *
 * <pre>{@code
 * @EventListener
 * void onPayment(WebhookEvent event) {
 *   if (event.isPaymentCompleted()) {
 *     orders.deliver(event.payment().reference()); // once: deduplicate on payment().id()
 *   }
 * }
 * }</pre>
 *
 * <p>Answers {@code 200 {"received":true}}, or {@code 400} for a wrong signature, an expired
 * timestamp or an invalid body. A listener that throws gives a {@code 500}: LabPay delivers the
 * notification again later (after 1, 5, 15, 60 and 240 minutes). Answer within 10 seconds: hand
 * slow work over to {@code @Async} listeners or a queue.
 */
@RestController
public final class LabPayWebhookController {

  private final List<String> secrets;
  private final Duration tolerance;
  private final ApplicationEventPublisher publisher;
  private final Clock clock;

  /**
   * Creates the endpoint.
   *
   * @param secret the signing secret (several may be separated by commas)
   * @param tolerance max age of the signed timestamp
   * @param publisher publisher of the verified events
   */
  public LabPayWebhookController(
      String secret, Duration tolerance, ApplicationEventPublisher publisher) {
    this(secret, tolerance, publisher, Clock.systemUTC());
  }

  LabPayWebhookController(
      String secret, Duration tolerance, ApplicationEventPublisher publisher, Clock clock) {
    this.secrets = split(secret);
    if (secrets.isEmpty()) {
      throw new IllegalArgumentException("labpay.webhook.secret cannot be empty.");
    }
    this.tolerance = tolerance == null ? Webhooks.DEFAULT_TOLERANCE : tolerance;
    this.publisher = Objects.requireNonNull(publisher, "publisher");
    this.clock = Objects.requireNonNull(clock, "clock");
  }

  private static List<String> split(String secret) {
    List<String> list = new ArrayList<>();
    if (secret != null) {
      for (String part : secret.split(",", -1)) {
        if (!part.trim().isEmpty()) {
          list.add(part.trim());
        }
      }
    }
    return List.copyOf(list);
  }

  /**
   * Receives a notification.
   *
   * @param body raw request body
   * @param signature {@code X-Labyrinthe-Signature} header
   * @return 200 when verified and handled, 400 otherwise
   */
  @PostMapping(
      path = "${labpay.webhook.path:/webhooks/labpay}",
      produces = MediaType.APPLICATION_JSON_VALUE)
  public ResponseEntity<String> receive(
      @RequestBody(required = false) byte[] body,
      @RequestHeader(name = Webhooks.SIGNATURE_HEADER, required = false) String signature) {
    WebhookEvent event;
    try {
      event =
          Webhooks.constructEvent(
              body == null ? new byte[0] : body, signature, secrets, tolerance, clock);
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      return ResponseEntity.badRequest()
          .contentType(MediaType.APPLICATION_JSON)
          .body("{\"received\":false,\"error\":\"" + e.code() + "\"}");
    }
    publisher.publishEvent(event);
    return ResponseEntity.ok().contentType(MediaType.APPLICATION_JSON).body("{\"received\":true}");
  }

  @Override
  public String toString() {
    return "LabPayWebhookController(secret=****, tolerance=" + tolerance + ")";
  }
}
