package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.time.Instant;
import java.util.Map;

/**
 * A new signing secret ({@code POST /V2/account/webhook-secret/rotate}).
 *
 * @param webhookSecret the new secret ({@code whsec_...})
 * @param previousExpiresAt the previous secret stays accepted until this date (24 h), or null
 */
public record WebhookSecret(String webhookSecret, Instant previousExpiresAt) {

  static WebhookSecret fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "data");
    return new WebhookSecret(
        Data.string(data, "webhook_secret"),
        Data.nullableInstant(data, "webhook_secret_previous_expires_at"));
  }

  /** The secret is masked. */
  @Override
  public String toString() {
    return "WebhookSecret(webhookSecret=whsec_****, previousExpiresAt=" + previousExpiresAt + ")";
  }
}
