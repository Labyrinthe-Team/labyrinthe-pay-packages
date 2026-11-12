package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;
import java.util.Optional;

/** The merchant account, keys and balances ({@code client.account()}). */
public final class AccountService extends Resource {

  AccountService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Mode, fee rates, test key and (live token only) the signing secret.
   *
   * @return the account
   */
  public Account get() {
    return Account.fromJson(
        transport.request("GET", "/account", RetryMode.SAFE, null, null, false).data());
  }

  /**
   * One balance per currency, in the mode of the key.
   *
   * @return the balances
   */
  public List<Balance> balances() {
    Object data = transport.request("GET", "/balances", RetryMode.SAFE, null, null, false).data();
    List<Balance> balances = new ArrayList<>();
    for (Object item : Data.array(data, "data")) {
      balances.add(Balance.fromJson(item));
    }
    return Collections.unmodifiableList(balances);
  }

  /**
   * The balance of one currency.
   *
   * @param currency e.g. {@code CDF} (any case)
   * @return the balance, empty when the account has none in this currency
   */
  public Optional<Balance> balance(String currency) {
    String code = currency == null ? "" : currency.trim().toUpperCase(Locale.ROOT);
    return balances().stream()
        .filter(b -> b.currency().toUpperCase(Locale.ROOT).equals(code))
        .findFirst();
  }

  /**
   * New signing secret; the previous one stays accepted 24 h. Live token only, never retried after
   * a network error or a 5xx.
   *
   * @return the new secret
   */
  public WebhookSecret rotateWebhookSecret() {
    return WebhookSecret.fromJson(
        transport
            .request(
                "POST",
                "/account/webhook-secret/rotate",
                RetryMode.THROTTLED_ONLY,
                null,
                null,
                false)
            .data());
  }

  /**
   * New test key, returned; the previous one stops working immediately. Live token only, never
   * retried after a network error or a 5xx.
   *
   * @return the new test key ({@code test_...})
   */
  public String rotateTestKey() {
    Object data =
        transport
            .request(
                "POST", "/account/test-key/rotate", RetryMode.THROTTLED_ONLY, null, null, false)
            .data();
    Object key = Data.object(data, "data").get("test_key");
    if (!(key instanceof String)) {
      throw Data.invalid("test_key", "a string");
    }
    return (String) key;
  }
}
