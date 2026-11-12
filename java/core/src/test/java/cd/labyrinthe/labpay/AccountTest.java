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
