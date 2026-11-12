package cd.labyrinthe.labpay.webhook;

import static org.junit.jupiter.api.Assertions.assertArrayEquals;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.labpay.PaymentStatus;
import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import cd.labyrinthe.labpay.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.labpay.exception.SignatureVerificationException;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.HexFormat;
import java.util.List;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.junit.jupiter.api.Test;

class WebhooksTest {

  private static final String SECRET = "whsec_test_secret";
  private static final long NOW = 1_794_391_243L;
  private static final String BODY =
      "{\"event\":\"payment.completed\",\"payment\":{\"id\":\"DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6\","
          + "\"reference\":\"ORDER-42\",\"operation\":\"deposit\",\"livemode\":false,"
          + "\"status\":\"completed\",\"gateway_status\":\"COMPLETED\",\"amount\":\"1000.00\","
          + "\"fee\":\"35.00\",\"total_amount\":\"1035.00\",\"currency\":\"CDF\","
          + "\"country\":\"COD\",\"phone\":\"0813456789\",\"provider\":\"VODACOM_MPESA_COD\","
          + "\"provider_transaction_id\":null,\"failure\":null,\"metadata\":{\"orderId\":\"42\"},"
          + "\"created_at\":\"2026-11-11T10:00:00+00:00\","
          + "\"completed_at\":\"2026-11-11T10:00:42+00:00\"},"
          + "\"sent_at\":\"2026-11-11T10:00:43+00:00\"}";
  private static final byte[] RAW = BODY.getBytes(StandardCharsets.UTF_8);

  private static Clock at(long seconds) {
    return Clock.fixed(Instant.ofEpochSecond(seconds), ZoneOffset.UTC);
  }

  private static String sign(byte[] body, String... secrets) {
    return Webhooks.sign(body, List.of(secrets), NOW);
  }

  @Test
  void signsLikeTheEngine() throws Exception {
    Mac mac = Mac.getInstance("HmacSHA256");
    mac.init(new SecretKeySpec(SECRET.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
    String expected =
        "t="
            + NOW
            + ",v1="
            + HexFormat.of()
                .formatHex(mac.doFinal((NOW + "." + BODY).getBytes(StandardCharsets.UTF_8)));
    assertEquals(expected, sign(RAW, SECRET));
  }

  @Test
  void constructsAVerifiedEvent() {
    WebhookEvent event =
        Webhooks.constructEvent(RAW, sign(RAW, SECRET), List.of(SECRET), null, at(NOW + 10));

    assertEquals("payment.completed", event.event());
    assertTrue(event.isPaymentCompleted());
    assertFalse(event.isPaymentFailed());
    assertEquals(NOW, event.timestamp());
    assertEquals(Instant.parse("2026-11-11T10:00:43Z"), event.sentAt());
    assertEquals(PaymentStatus.COMPLETED, event.payment().status());
    assertEquals("ORDER-42", event.payment().reference());
  }

  @Test
  void acceptsEitherSignatureDuringARotation() {
