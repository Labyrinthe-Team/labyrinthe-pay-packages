package cd.labyrinthe.labpay;

import static cd.labyrinthe.labpay.MockApi.data;
import static cd.labyrinthe.labpay.MockApi.json;
import static cd.labyrinthe.labpay.MockApi.payment;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.labpay.exception.ConnectionException;
import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import java.math.BigDecimal;
import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.function.Consumer;
import java.util.stream.Stream;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

class PaymentsTest {

  private final MockApi api = new MockApi();

  @AfterEach
  void close() {
    api.close();
  }

  @Test
  void sendsTheDocumentedBodyAndHeaders() {
    api.enqueue(data(201, payment()));
    LabPayClient labpay = api.client();

    Payment result =
        labpay
            .deposits()
            .create(
                MobileMoneyParams.builder()
                    .amount(1000)
                    .currency("cdf")
                    .phone("0813456789")
                    .reference("ORDER-42")
                    .callbackUrl("https://boutique.example/webhooks/labpay")
                    .customerMessage("Commande 42")
                    .metadata("orderId", 42)
                    .build());

    MockApi.Recorded request = api.last();
    assertEquals("POST", request.method);
    assertEquals("/api/V2/deposits", request.path);
    assertEquals("Bearer " + MockApi.KEY, request.header("Authorization"));
    assertEquals("application/json", request.header("Content-Type"));
    assertTrue(request.header("User-Agent").startsWith("labpay-java/"));
    assertEquals(
        "{\"amount\":\"1000\",\"currency\":\"CDF\",\"phone\":\"0813456789\","
            + "\"callback_url\":\"https://boutique.example/webhooks/labpay\","
            + "\"customer_message\":\"Commande 42\",\"metadata\":{\"orderId\":\"42\"},"
            + "\"reference\":\"ORDER-42\"}",
        request.body);
    assertEquals("DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6", result.id());
    assertEquals("1035.00", result.totalAmount());
    assertEquals(new BigDecimal("1000.00"), result.amountValue());
    assertEquals(Operation.DEPOSIT, result.operation());
    assertEquals(PaymentStatus.PENDING, result.status());
    assertFalse(result.livemode());
    assertFalse(result.isFinal());
    assertEquals(Map.of("orderId", "42"), result.metadata());
    assertEquals(Instant.parse("2026-11-11T10:00:00Z"), result.createdAt());
    assertEquals(Mode.TEST, labpay.lastResponse().orElseThrow().mode().orElseThrow());
    assertFalse(result.toString().contains("0813456789"));
  }

  @Test
  void formatsExactDecimalAmounts() {
    api.enqueue(data(201, payment()), data(201, payment()));
    LabPayClient labpay = api.client();

    labpay
        .payouts()
        .create(
            MobileMoneyParams.builder()
                .amount(new BigDecimal("12.5"))
                .currency("USD")
                .phone("0813456789")
                .reference("A")
                .build());
    assertEquals("12.50", api.last().json().get("amount"));
    labpay
        .payouts()
        .create(
            MobileMoneyParams.builder()
                .amount(new BigDecimal("1500.00"))
                .currency("CDF")
                .phone("0813456789")
                .reference("B")
                .build());
    assertEquals("1500", api.last().json().get("amount"));
  }

  @Test
  void generatesAReferenceWhenMissingAndKeepsItAcrossRetries() {
    api.enqueue(MockApi.raw(500, ""), data(201, payment()));
    LabPayClient labpay = api.client();

    labpay.deposits().create(MobileMoneyParams.of("12.50", "USD", "+243813456789"));

    assertEquals(2, api.requests.size());
    String reference = (String) api.requests.get(0).json().get("reference");
    assertTrue(
        reference.matches(
            "^sdk_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"),
        reference);
    assertEquals(api.requests.get(0).body, api.requests.get(1).body);
    assertEquals(1, api.sleeper.sleeps.size());
  }

  @Test
  void doesNotRetryAPaymentWithoutReferenceAfterANetworkError() {
    api.enqueue(MockApi.disconnect());
    LabPayClient labpay = api.builder().autoReference(false).build();

    ConnectionException error =
        assertThrows(
            ConnectionException.class,
            () -> labpay.payouts().create(MobileMoneyParams.of("5000", "CDF", "0813456789")));

    assertEquals("CONNECTION_ERROR", error.code());
    assertTrue(error.getMessage().contains("it was not retried automatically"));
    assertEquals(1, api.requests.size());
    assertEquals("/api/V2/payouts", api.requests.get(0).path);
    assertFalse(api.requests.get(0).json().containsKey("reference"));
  }

  @Test
  void returnsAPaymentRefusedAtInitiationInsteadOfThrowing() {
    api.enqueue(
        data(
            422,
            payment(
                "status",
                "\"failed\"",
                "failure",
                "{\"code\":\"PAYER_LIMIT_REACHED\",\"message\":\"Limite\"}")));
    LabPayClient labpay = api.client();

    Payment result = labpay.deposits().create(MobileMoneyParams.of("1000", "CDF", "243813456019"));

    assertEquals(PaymentStatus.FAILED, result.status());
    assertEquals(new PaymentFailure("PAYER_LIMIT_REACHED", "Limite"), result.failure());
    assertEquals(422, labpay.lastResponse().orElseThrow().status());
  }

  @Test
  void createsARefund() {
    api.enqueue(
        data(
            201,
            payment(
                "id", "\"RFD-1\"", "operation", "\"refund\"", "refunded_payment_id", "\"DEP-1\"")));
    LabPayClient labpay = api.client();

    Payment refund =
        labpay
            .refunds()
            .create(
                RefundParams.builder()
                    .paymentId("DEP-1")
                    .amount("300")
                    .reference("REFUND-42-1")
                    .build());

    assertEquals(
        "{\"payment_id\":\"DEP-1\",\"amount\":\"300\",\"reference\":\"REFUND-42-1\"}",
