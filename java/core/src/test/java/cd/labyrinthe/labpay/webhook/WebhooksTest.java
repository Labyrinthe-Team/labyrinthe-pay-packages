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
    String header = sign(RAW, "whsec_new", SECRET);

    assertEquals(2, header.split("v1=", -1).length - 1);
    assertTrue(Webhooks.isValid(RAW, header, List.of(SECRET), null, at(NOW)));
    assertTrue(Webhooks.isValid(RAW, header, List.of("whsec_new"), null, at(NOW)));
    assertFalse(Webhooks.isValid(RAW, header, List.of("whsec_other"), null, at(NOW)));
    assertTrue(
        Webhooks.isValid(RAW, sign(RAW, SECRET), List.of("whsec_new", SECRET), null, at(NOW)));
    // En-tête en majuscules : accepté (hexadécimal insensible à la casse).
    assertTrue(
        Webhooks.isValid(
            RAW,
            header.toUpperCase().replace("V1=", "v1=").replace("T=", "t="),
            List.of(SECRET),
            null,
            at(NOW)));
  }

  @Test
  void refusesATamperedBodyAnOldTimestampAndMalformedHeaders() {
    String header = sign(RAW, SECRET);
    byte[] tampered = BODY.replace("1000.00", "9000.00").getBytes(StandardCharsets.UTF_8);

    assertThrows(
        SignatureVerificationException.class,
        () -> Webhooks.constructEvent(tampered, header, List.of(SECRET), null, at(NOW)));
    SignatureVerificationException old =
        assertThrows(
            SignatureVerificationException.class,
            () -> Webhooks.verify(RAW, header, List.of(SECRET), null, at(NOW + 301)));
    assertTrue(old.getMessage().contains("tolerance"));
    assertTrue(Webhooks.isValid(RAW, header, List.of(SECRET), Duration.ZERO, at(NOW + 301)));
    for (String bad : new String[] {"", "t=123", "v1=" + "a".repeat(64), "t=123,v1=abc", null}) {
      assertThrows(
          SignatureVerificationException.class,
          () -> Webhooks.verify(RAW, bad, List.of(SECRET), null, at(NOW)),
          String.valueOf(bad));
    }
  }

  @Test
  void refusesASignedBodyThatIsNotANotification() {
    byte[] invalid = "{\"event\":\"payment.completed\"}".getBytes(StandardCharsets.UTF_8);
    InvalidWebhookPayloadException e =
        assertThrows(
            InvalidWebhookPayloadException.class,
            () ->
                Webhooks.constructEvent(
                    invalid, sign(invalid, SECRET), List.of(SECRET), null, at(NOW)));
    assertTrue(e.getMessage().startsWith("Invalid notification payload"), e.getMessage());

    byte[] notJson = "nope".getBytes(StandardCharsets.UTF_8);
    assertThrows(
        InvalidWebhookPayloadException.class,
        () ->
            Webhooks.constructEvent(
                notJson, sign(notJson, SECRET), List.of(SECRET), null, at(NOW)));
  }

  @Test
  void refusesAnEmptySecret() {
    InvalidArgumentException e =
        assertThrows(
            InvalidArgumentException.class,
            () -> Webhooks.verify(RAW, "t=1,v1=" + "a".repeat(64), ""));
    assertEquals("INVALID_ARGUMENT", e.code());
  }

  @Test
  void acceptsTextBodiesAndTheDefaultClock() {
    String header = Webhooks.sign(RAW, SECRET);
    assertEquals("ORDER-42", Webhooks.constructEvent(BODY, header, SECRET).payment().reference());
    assertArrayEquals(RAW, BODY.getBytes(StandardCharsets.UTF_8));
  }
}
