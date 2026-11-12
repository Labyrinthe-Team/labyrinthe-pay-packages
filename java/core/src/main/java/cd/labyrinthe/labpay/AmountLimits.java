package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.Map;

/**
 * Amount limits of a currency.
 *
 * @param minAmount smallest amount, or null
 * @param maxAmount largest amount, or null
 */
public record AmountLimits(String minAmount, String maxAmount) {

  static AmountLimits fromJson(Object value) {
    Map<String, Object> data = Data.lenientObject(value);
    return new AmountLimits(
        Data.nullableString(data, "min_amount"), Data.nullableString(data, "max_amount"));
  }
}
