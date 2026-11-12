package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.UUID;

/** Base commune des services : transport, référence automatique, initiation d'un paiement. */
abstract class Resource {

  /** Préfixe des références générées par le SDK pour les paiements envoyés sans référence. */
  static final String AUTO_REFERENCE_PREFIX = "sdk_";

  final Transport transport;
  private final boolean autoReference;

  Resource(Transport transport, boolean autoReference) {
    this.transport = transport;
    this.autoReference = autoReference;
  }

  /**
   * La référence donnée (validée), sinon une référence générée {@code sdk_<uuid v4>}, sinon null.
   */
  final String reference(String value) {
    String reference = Input.reference(value);
    if (reference != null) {
      return reference;
    }
    return autoReference ? AUTO_REFERENCE_PREFIX + UUID.randomUUID() : null;
  }

  /** Dépôt ou envoi : même corps, même politique de relance. */
  final Payment initiate(String path, MobileMoneyParams params) {
    if (params == null) {
      throw new cd.labyrinthe.labpay.exception.InvalidArgumentException("params are required.");
    }
    Map<String, Object> body = new LinkedHashMap<>();
    body.put("amount", Input.amount(params.amount(), "amount"));
    body.put("currency", Input.currency(params.currency()));
    body.put("phone", Input.phone(params.phone()));
    putIfPresent(body, "country", Input.country(params.country()));
    putIfPresent(body, "provider", Input.provider(params.provider()));
    putIfPresent(body, "callback_url", Input.callbackUrl(params.callbackUrl()));
    putIfPresent(body, "customer_message", Input.customerMessage(params.customerMessage()));
    putIfPresent(body, "metadata", Input.metadata(params.metadata()));
    String reference = reference(params.reference());
    putIfPresent(body, "reference", reference);
    // Avec une référence, un renvoi rend le paiement existant : la relance est sûre.
    Transport.Response response =
        transport.request(
            "POST",
            path,
            reference == null ? RetryMode.THROTTLED_ONLY : RetryMode.SAFE,
            null,
            body,
            true);
    return Payment.fromJson(response.data());
  }

  static void putIfPresent(Map<String, Object> map, String key, Object value) {
    if (value != null) {
      map.put(key, value);
    }
  }
}
