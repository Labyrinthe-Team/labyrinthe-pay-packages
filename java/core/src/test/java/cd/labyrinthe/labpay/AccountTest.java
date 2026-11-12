package cd.labyrinthe.labpay;

import static cd.labyrinthe.labpay.MockApi.data;
import static cd.labyrinthe.labpay.MockApi.json;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import java.math.BigDecimal;
import java.net.http.HttpClient;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

class AccountTest {

  private final MockApi api = new MockApi();

  @AfterEach
  void close() {
    api.close();
  }

  @Test
  void readsTheAccountAndBalancesAndRotatesKeys() {
    api.enqueue(
        data(
            200,
            "{\"mode\":\"live\",\"merchant\":\"Boutique\",\"live_enabled\":true,"
                + "\"kyc_verified\":true,\"fees\":{\"deposit_rate\":\"0.0350\","
                + "\"payout_rate\":\"0.0350\"},\"test_key\":\"test_abc\","
                + "\"webhook_secret\":\"whsec_secret\"}"),
        data(
            200,
            "[{\"currency\":\"CDF\",\"balance\":\"125000.00\","
                + "\"updated_at\":\"2026-11-11T10:00:42+00:00\"},"
                + "{\"currency\":\"USD\",\"balance\":\"0.00\",\"updated_at\":null}]"),
        data(
            200,
            "{\"webhook_secret\":\"whsec_new\","
                + "\"webhook_secret_previous_expires_at\":\"2026-11-12T10:00:00+00:00\"}"),
        data(200, "{\"test_key\":\"test_new\"}"));
    LabPayClient labpay = api.builder().apiKey("live_token_abcdefgh").build();

    Account account = labpay.account().get();
    assertEquals(Mode.LIVE, account.mode());
    assertTrue(account.liveEnabled());
    assertEquals("0.0350", account.depositFeeRate());
    assertEquals("whsec_secret", account.webhookSecret());
    assertNull(account.webhookSecretPreviousExpiresAt());
    assertFalse(account.toString().contains("whsec_secret"));

    Balance cdf = labpay.account().balance("cdf").orElseThrow();
    assertEquals(new BigDecimal("125000.00"), cdf.value());
    assertEquals("/api/V2/balances", api.last().path);

    WebhookSecret secret = labpay.account().rotateWebhookSecret();
    assertEquals("whsec_new", secret.webhookSecret());
    assertEquals("/api/V2/account/webhook-secret/rotate", api.last().path);
    assertFalse(secret.toString().contains("whsec_new"));
    assertEquals("test_new", labpay.account().rotateTestKey());
    assertEquals("/api/V2/account/test-key/rotate", api.last().path);
  }

  @Test
  void readsCountriesProvidersAvailabilityAndPredictions() {
    api.enqueue(
        data(
            200,
            "[{\"country\":\"COD\",\"name\":\"RD Congo\",\"dial_code\":\"243\","
                + "\"currencies\":[\"CDF\",\"USD\"],\"limits\":{\"CDF\":{\"min_amount\":\"100\","
                + "\"max_amount\":\"20000000\"},\"USD\":{\"min_amount\":null,"
                + "\"max_amount\":\"10000\"}},\"default\":true}]"),
        data(
            200,
            "[{\"provider\":\"VODACOM_MPESA_COD\",\"country\":\"COD\",\"name\":\"Vodacom M-Pesa\","
                + "\"name_displayed_to_customer\":\"M-PESA\",\"logo\":null,\"currencies\":{\"CDF\":"
                + "{\"min_amount\":\"100\",\"max_amount\":\"2500000\",\"decimals\":\"NONE\","
                + "\"status\":\"OPERATIONAL\",\"pin_prompt\":\"AUTOMATIC\","
                + "\"pin_prompt_revivable\":false,\"pin_prompt_instructions\":null}}}]"),
        data(
            200,
            "{\"VODACOM_MPESA_COD\":{\"country\":\"COD\",\"DEPOSIT\":\"OPERATIONAL\","
                + "\"PAYOUT\":\"DELAYED\"}}"),
        data(200, "[]"),
        data(
            200,
            "{\"phone\":\"243813456789\",\"provider\":\"VODACOM_MPESA_COD\",\"country\":\"COD\"}"));
    LabPayClient labpay = api.client();

    Country country = labpay.providers().countries().get(0);
    assertEquals("243", country.dialCode());
    assertTrue(country.isDefault());
    assertEquals(new AmountLimits(null, "10000"), country.limits().get("USD"));

    Provider provider = labpay.providers().list(Operation.PAYOUT, "cod").get(0);
    assertEquals("operation=payout&country=COD", api.last().rawQuery);
    assertEquals("NONE", provider.currencies().get("CDF").decimals());
    assertEquals(Boolean.FALSE, provider.currencies().get("CDF").pinPromptRevivable());

    Map<String, ProviderAvailability> availability = labpay.providers().availability("COD");
    assertEquals(
        new ProviderAvailability(
            "VODACOM_MPESA_COD", "COD", Map.of("DEPOSIT", "OPERATIONAL", "PAYOUT", "DELAYED")),
        availability.get("VODACOM_MPESA_COD"));
    // PHP encode un objet vide en [].
    assertTrue(labpay.providers().availability().isEmpty());

    ProviderPrediction prediction = labpay.providers().predict("0813456789", "COD");
    assertEquals("{\"phone\":\"0813456789\",\"country\":\"COD\"}", api.last().body);
    assertEquals("243813456789", prediction.phone());
  }

  @Test
  void listsAndResendsNotifications() {
    String notification =
        "\"event\":\"payment.completed\",\"payment_id\":\"DEP-1\","
            + "\"url\":\"https://boutique.example/webhooks/labpay\",\"last_error\":\"HTTP 500\","
            + "\"next_attempt_at\":null,\"delivered_at\":null,"
            + "\"created_at\":\"2026-11-11T10:00:43+00:00\",\"last_status_code\":500,";
    api.enqueue(
        json(
            200,
            "{\"data\":[{\"id\":12,"
                + notification
                + "\"status\":\"abandoned\",\"attempts\":6}],"
                + "\"meta\":{\"current_page\":1,\"last_page\":1,\"per_page\":20,\"total\":1}}"),
        data(202, "{\"id\":13," + notification + "\"status\":\"pending\",\"attempts\":0}"));
    LabPayClient labpay = api.client();

    Page<Notification> page =
        labpay
            .notifications()
            .list(
                ListNotificationsParams.builder()
                    .paymentId("DEP-1")
                    .status(NotificationStatus.ABANDONED)
                    .build());
    assertEquals("payment_id=DEP-1&status=abandoned", api.last().rawQuery);
    assertFalse(page.hasMore());
    assertEquals(1, page.total());
    Notification first = page.data().get(0);
    assertEquals(12, first.id());
    assertEquals(500, first.lastStatusCode());
    assertEquals(WebhookEventType.PAYMENT_COMPLETED, first.type());

    Notification resent = labpay.notifications().resend(12);
    assertEquals("/api/V2/notifications/12/resend", api.last().path);
    assertEquals(13, resent.id());
    assertEquals(NotificationStatus.PENDING, resent.status());
  }

  @Test
  void normalizesTheBaseUrl() {
    LabPayClient.Builder builder = LabPayClient.builder().apiKey("test_x").environment(n -> null);
    assertEquals("https://api.labyrinthe-rdc.com/api/V2", builder.build().baseUrl());
    assertEquals(
        "http://localhost:8000/api/V2", builder.baseUrl("http://localhost:8000").build().baseUrl());
    assertEquals(
        "https://pay.example/api/V2",
        builder.baseUrl("https://pay.example/api/V2/").build().baseUrl());
    assertThrows(InvalidArgumentException.class, () -> builder.baseUrl("ftp://x").build());
  }

  @Test
  void knowsTheModeOfTheKeyAndNeverPrintsIt() {
    LabPayClient live =
        LabPayClient.builder().apiKey("live_secret_token_value").environment(n -> null).build();
    assertFalse(live.isTestMode());
    assertTrue(LabPayClient.builder().apiKey("test_x").build().isTestMode());
    assertFalse(live.toString().contains("live_secret_token_value"));
  }

  @Test
  void readsTheKeyFromTheEnvironmentAndRequiresOne() {
    LabPayClient fromEnv =
        LabPayClient.builder()
            .environment(n -> n.equals(LabPayClient.ENV_API_KEY) ? "test_from_env" : null)
            .build();
    assertTrue(fromEnv.isTestMode());
    assertThrows(
        InvalidArgumentException.class,
        () -> LabPayClient.builder().apiKey(" ").environment(n -> null).build());
  }

  @Test
  void refusesAnHttpClientThatFollowsRedirects() {
    HttpClient redirecting =
        HttpClient.newBuilder().followRedirects(HttpClient.Redirect.NORMAL).build();
    assertThrows(
        InvalidArgumentException.class,
        () -> LabPayClient.builder().apiKey("test_x").httpClient(redirecting).build());
  }

  @Test
  void exposesTheVersion() {
    assertEquals(System.getProperty("labpay.expectedVersion", Version.VERSION), Version.VERSION);
    assertTrue(Version.userAgent().startsWith("labpay-java/" + Version.VERSION + " Java/"));
    assertEquals(List.of("V2"), List.of(Version.API_VERSION));
  }
}
