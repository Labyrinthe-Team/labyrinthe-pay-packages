package cd.labyrinthe.labpay;

import static cd.labyrinthe.labpay.MockApi.data;
import static cd.labyrinthe.labpay.MockApi.error;
import static cd.labyrinthe.labpay.MockApi.json;
import static cd.labyrinthe.labpay.MockApi.payment;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.labpay.exception.AmountOutOfRangeException;
import cd.labyrinthe.labpay.exception.ApiException;
import cd.labyrinthe.labpay.exception.AuthenticationException;
import cd.labyrinthe.labpay.exception.ConflictException;
import cd.labyrinthe.labpay.exception.ConnectionException;
import cd.labyrinthe.labpay.exception.DailyLimitReachedException;
import cd.labyrinthe.labpay.exception.InsufficientBalanceException;
import cd.labyrinthe.labpay.exception.InvalidPhoneNumberException;
import cd.labyrinthe.labpay.exception.InvalidRequestException;
import cd.labyrinthe.labpay.exception.LabPayException;
import cd.labyrinthe.labpay.exception.NotFoundException;
import cd.labyrinthe.labpay.exception.PermissionException;
import cd.labyrinthe.labpay.exception.RateLimitException;
import cd.labyrinthe.labpay.exception.ServiceUnavailableException;
import cd.labyrinthe.labpay.exception.UnexpectedResponseException;
import cd.labyrinthe.labpay.exception.ValidationException;
import java.time.Duration;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

class ErrorsTest {

  private final MockApi api = new MockApi();

  @AfterEach
  void close() {
    api.close();
  }

  static Stream<Arguments> typedErrors() {
    return Stream.of(
        Arguments.of(401, "UNAUTHENTICATED", AuthenticationException.class),
        Arguments.of(403, "KYC_REQUIRED", PermissionException.class),
        Arguments.of(403, "LIVE_TOKEN_REQUIRED", PermissionException.class),
        Arguments.of(404, "PAYMENT_NOT_FOUND", NotFoundException.class),
        Arguments.of(409, "REFUND_IN_PROGRESS", ConflictException.class),
        Arguments.of(422, "INVALID_PHONE_NUMBER", InvalidPhoneNumberException.class),
        Arguments.of(422, "INVALID_CURRENCY", InvalidRequestException.class),
        Arguments.of(422, "AMOUNT_TOO_SMALL", AmountOutOfRangeException.class),
        Arguments.of(422, "INSUFFICIENT_BALANCE", InsufficientBalanceException.class),
        Arguments.of(429, "DAILY_LIMIT_REACHED", DailyLimitReachedException.class),
        Arguments.of(503, "MAINTENANCE", ServiceUnavailableException.class),
        Arguments.of(418, "SOMETHING_NEW", ApiException.class));
  }

  @ParameterizedTest(name = "{0} {1}")
  @MethodSource("typedErrors")
  void mapsErrorsByCodeThenStatus(int status, String code, Class<?> type) {
    api.enqueue(error(status, code));
    LabPayClient labpay = api.builder().maxRetries(0).build();

    ApiException e = assertThrows(ApiException.class, () -> labpay.payments().get("DEP-1"));

    assertEquals(type, e.getClass());
    assertInstanceOf(LabPayException.class, e);
    assertEquals(code, e.code());
    assertEquals(status, e.statusCode());
    assertEquals("Message en français", e.getMessage());
    assertEquals("test", e.mode().orElseThrow());
  }

  @Test
  void exposesValidationErrors() {
    api.enqueue(
        json(
            422,
            "{\"message\":\"Montant invalide\",\"code\":\"VALIDATION_ERROR\","
                + "\"errors\":{\"amount\":[\"Montant invalide\"]}}"));
    LabPayClient labpay = api.client();

    ValidationException e =
        assertThrows(
            ValidationException.class,
            () -> labpay.deposits().create(MobileMoneyParams.of("10", "CDF", "0813456789")));

    assertEquals(Map.of("amount", List.of("Montant invalide")), e.errors());
  }

  @Test
  void mapsA422WithoutCodeButWithErrorsToAValidationError() {
    api.enqueue(
        json(
            422,
            "{\"message\":\"The selected status is invalid.\","
