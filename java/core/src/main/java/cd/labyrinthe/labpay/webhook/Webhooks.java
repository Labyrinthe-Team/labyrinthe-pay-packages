package cd.labyrinthe.labpay.webhook;

import cd.labyrinthe.labpay.Payment;
import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import cd.labyrinthe.labpay.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.labpay.exception.SignatureVerificationException;
import cd.labyrinthe.labpay.exception.UnexpectedResponseException;
import cd.labyrinthe.labpay.internal.Data;
import cd.labyrinthe.labpay.internal.Json;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.time.Duration;
import java.util.ArrayList;
import java.util.Collection;
import java.util.HexFormat;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * Verification of the notifications sent to your {@code callback_url}.
 *
 * <p>{@code X-Labyrinthe-Signature: t=<timestamp>,v1=<hex>[,v1=<hex>]}: each {@code v1} is the
 * HMAC-SHA256 of {@code "<timestamp>.<raw body>"} keyed with your signing secret ({@code whsec_…},
 * {@code GET /V2/account} with the live token). For 24 h after a rotation the header carries two
 * {@code v1}; the notification is accepted when either matches.
 *
 * <pre>{@code
 * WebhookEvent event =
 *     Webhooks.constructEvent(rawBody, request.getHeader(Webhooks.SIGNATURE_HEADER), secret);
 * if (event.isPaymentCompleted()) {
 *   orders.deliver(event.payment().reference()); // once: deduplicate on payment().id()
 * }
 * }</pre>
 */
public final class Webhooks {

  /** Signature header: {@value}. */
  public static final String SIGNATURE_HEADER = "X-Labyrinthe-Signature";

  /** Event header: {@value}. */
  public static final String EVENT_HEADER = "X-Labyrinthe-Event";

  /** Delivery id header (notification id): {@value}. */
  public static final String DELIVERY_HEADER = "X-Labyrinthe-Delivery";

  /** Mode header ({@code live} or {@code test}): {@value}. */
  public static final String MODE_HEADER = "X-Labyrinthe-Mode";

  /** Default max age of the signed timestamp: 300 seconds. */
  public static final Duration DEFAULT_TOLERANCE = Duration.ofSeconds(300);

  private static final Pattern TIMESTAMP = Pattern.compile("^\\d{1,12}$");
  private static final Pattern SIGNATURE = Pattern.compile("^[0-9a-fA-F]{64}$");
  private static final HexFormat HEX = HexFormat.of();

  private Webhooks() {}

  /**
   * Verifies the signature and the timestamp (300 s tolerance), then returns the typed event.
   *
   * @param rawBody the request body, byte for byte (never a re-encoded object)
   * @param signatureHeader value of {@code X-Labyrinthe-Signature} (null when missing)
   * @param secret your signing secret ({@code whsec_…})
   * @return the event
   * @throws SignatureVerificationException wrong/missing signature or old timestamp: answer 400
   * @throws InvalidWebhookPayloadException signed body that is not a notification: answer 400
   */
  public static WebhookEvent constructEvent(byte[] rawBody, String signatureHeader, String secret) {
    return constructEvent(
        rawBody, signatureHeader, List.of(nonNull(secret)), DEFAULT_TOLERANCE, Clock.systemUTC());
  }

  /**
   * Same as {@link #constructEvent(byte[], String, String)} for a body read as UTF-8 text.
   *
   * @param rawBody the request body as received
   * @param signatureHeader value of {@code X-Labyrinthe-Signature}
   * @param secret your signing secret
   * @return the event
   */
  public static WebhookEvent constructEvent(String rawBody, String signatureHeader, String secret) {
    return constructEvent(bytes(rawBody), signatureHeader, secret);
  }

  /**
   * Verifies a notification with several accepted secrets (e.g. during your own rotation), a custom
   * tolerance and clock.
   *
   * @param rawBody the request body, byte for byte
   * @param signatureHeader value of {@code X-Labyrinthe-Signature}
   * @param secrets accepted secrets (at least one non-empty)
   * @param tolerance max age of the signed timestamp; {@link Duration#ZERO} disables the check (not
   *     recommended); null for the default
   * @param clock current time (tests)
   * @return the event
   * @throws SignatureVerificationException wrong/missing signature or old timestamp: answer 400
   * @throws InvalidWebhookPayloadException signed body that is not a notification: answer 400
   */
  public static WebhookEvent constructEvent(
      byte[] rawBody,
      String signatureHeader,
      Collection<String> secrets,
      Duration tolerance,
      Clock clock) {
    long timestamp = verify(rawBody, signatureHeader, secrets, tolerance, clock);
    Object decoded;
    try {
      decoded = Json.parse(new String(rawBody, StandardCharsets.UTF_8));
    } catch (Json.JsonException e) {
      throw new InvalidWebhookPayloadException("The notification body is not valid JSON.");
    }
    if (!(decoded instanceof Map)) {
      throw new InvalidWebhookPayloadException("The notification body is not a JSON object.");
    }
    Map<String, Object> body = Data.lenientObject(decoded);
    if (!(body.get("event") instanceof String)) {
      throw new InvalidWebhookPayloadException(
          "Invalid notification payload: \"event\" should be a string.");
    }
    Payment payment;
    try {
      payment = Payment.fromJson(body.get("payment"));
    } catch (UnexpectedResponseException e) {
      throw new InvalidWebhookPayloadException(
          e.getMessage().replace(Data.CONTEXT, "Invalid notification payload"));
    }
    return new WebhookEvent(
        (String) body.get("event"),
        payment,
        Data.nullableInstant(body, "sent_at"),
        timestamp,
        body);
  }

  /**
   * Checks the signature (constant time) and the timestamp (300 s tolerance).
   *
   * @param rawBody the request body, byte for byte
   * @param signatureHeader value of {@code X-Labyrinthe-Signature}
   * @param secret your signing secret
   * @return the signed timestamp
   * @throws SignatureVerificationException wrong/missing signature or old timestamp
   */
  public static long verify(byte[] rawBody, String signatureHeader, String secret) {
    return verify(
        rawBody, signatureHeader, List.of(nonNull(secret)), DEFAULT_TOLERANCE, Clock.systemUTC());
  }

  /**
   * Checks the signature (constant time) against every secret and every {@code v1}, then the
   * timestamp.
   *
   * @param rawBody the request body, byte for byte
   * @param signatureHeader value of {@code X-Labyrinthe-Signature}
   * @param secrets accepted secrets
   * @param tolerance max age; {@link Duration#ZERO} disables the check; null for the default
   * @param clock current time
   * @return the signed timestamp
   * @throws SignatureVerificationException wrong/missing signature or old timestamp
   */
