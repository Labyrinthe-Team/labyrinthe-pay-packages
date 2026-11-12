package cd.labyrinthe.labpay;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.labpay.exception.ApiException;
import cd.labyrinthe.labpay.internal.ErrorMapper;
import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;
import org.yaml.snakeyaml.Yaml;

/** Le SDK suit la spécification {@code ../openapi/openapi.yaml}, source commune des SDK. */
class ContractTest {

  /** Opérations appelées par le SDK (méthode + chemin relatif à /V2). */
  private static final List<String> SDK_OPERATIONS =
      List.of(
          "post /deposits",
          "post /payouts",
          "post /payouts/{id}/cancel",
          "post /refunds",
          "get /payments",
          "get /payments/{id}",
          "post /payments/{id}/sync",
          "get /countries",
          "get /providers",
          "get /availability",
          "post /predict-provider",
          "get /balances",
          "get /account",
          "post /account/webhook-secret/rotate",
          "post /account/test-key/rotate",
          "get /notifications",
          "post /notifications/{id}/resend");

  @SuppressWarnings("unchecked")
  private static Map<String, Object> spec() throws IOException {
    Path path = Path.of(System.getProperty("labpay.specPath", "../../openapi/openapi.yaml"));
    try (InputStream in = Files.newInputStream(path)) {
      return (Map<String, Object>) new Yaml().load(in);
    }
  }

  @SuppressWarnings("unchecked")
  private static List<String> enumOf(Map<String, Object> spec, String schema) {
    Map<String, Object> components = (Map<String, Object>) spec.get("components");
    Map<String, Object> schemas = (Map<String, Object>) components.get("schemas");
    return (List<String>) ((Map<String, Object>) schemas.get(schema)).get("enum");
  }

  @Test
  @SuppressWarnings("unchecked")
  void coversExactlyTheOperationsOfTheSpec() throws IOException {
    List<String> operations = new ArrayList<>();
    ((Map<String, Object>) spec().get("paths"))
        .forEach(
            (path, item) ->
                ((Map<String, Object>) item)
                    .keySet()
                    .forEach(method -> operations.add(method + " " + path)));
    List<String> expected = new ArrayList<>(SDK_OPERATIONS);
    Collections.sort(operations);
    Collections.sort(expected);
    assertEquals(expected, operations);
  }

  @Test
  void mapsEveryDocumentedErrorCodeToATypedException() throws IOException {
    List<String> codes = enumOf(spec(), "ErrorCode");
    assertTrue(codes.size() > 20);
    for (String code : codes) {
      // Statut neutre : seule la table des codes peut donner une sous-classe.
      ApiException e =
          ErrorMapper.fromResponse(418, Map.of("message", "x", "code", code), Map.of());
      assertNotEquals(ApiException.class, e.getClass(), code);
    }
  }

  @Test
  void keepsTheEnumsOfTheSdk() throws IOException {
    Map<String, Object> spec = spec();
    assertEquals(values(PaymentStatus.values()), enumOf(spec, "PaymentStatus"));
    assertEquals(values(Operation.values()), enumOf(spec, "Operation"));
    assertEquals(values(NotificationStatus.values()), enumOf(spec, "NotificationStatus"));
    assertEquals(values(WebhookEventType.values()), enumOf(spec, "WebhookEventType"));
    assertEquals(values(Mode.values()), enumOf(spec, "Mode"));
  }

  private static List<String> values(Enum<?>[] constants) {
    List<String> values = new ArrayList<>();
    for (Enum<?> constant : constants) {
      if (!constant.name().equals("UNKNOWN")) {
        values.add(constant.toString());
      }
    }
    return values;
  }
}
