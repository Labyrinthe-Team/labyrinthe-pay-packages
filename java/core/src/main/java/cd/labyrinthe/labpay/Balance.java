package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.Map;

/**
 * Balance of one currency, in the mode of the key.
 *
 * @param currency {@code CDF} or {@code USD}
 * @param balance string with 2 decimals, e.g. {@code "125000.00"}
 * @param updatedAt last change, or null
 */
public record Balance(String currency, String balance, Instant updatedAt) {

  /**
   * The balance as an exact decimal.
   *
   * @return e.g. {@code 125000.00}
   */
  public BigDecimal value() {
    return new BigDecimal(balance);
  }

  static Balance fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "balance");
    return new Balance(
        Data.string(data, "currency"),
        Data.string(data, "balance"),
        Data.nullableInstant(data, "updated_at"));
  }
}
