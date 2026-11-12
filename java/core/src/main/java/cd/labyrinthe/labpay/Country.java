package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * A country open to payments ({@code GET /V2/countries}).
 *
 * @param country ISO 3166-1 alpha-3 code, e.g. {@code COD}
 * @param name name, or null
 * @param dialCode dial code without "+", e.g. {@code 243}, or null
 * @param currencies accepted currencies
 * @param limits amount limits per currency
 * @param isDefault country of a local number sent without {@code country}
 */
public record Country(
    String country,
    String name,
    String dialCode,
    List<String> currencies,
    Map<String, AmountLimits> limits,
    boolean isDefault) {

  /**
   * Creates a country.
   *
   * @param country ISO 3166-1 alpha-3 code
   * @param name name, or null
   * @param dialCode dial code, or null
   * @param currencies accepted currencies
   * @param limits amount limits per currency
   * @param isDefault country of a local number sent without {@code country}
   */
  public Country {
    currencies = List.copyOf(currencies);
    limits = Collections.unmodifiableMap(new LinkedHashMap<>(limits));
  }

  static Country fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "country");
    Map<String, AmountLimits> limits = new LinkedHashMap<>();
    Data.optionalObject(data, "limits")
        .forEach((currency, limit) -> limits.put(currency, AmountLimits.fromJson(limit)));
    List<String> currencies = new ArrayList<>();
    if (data.get("currencies") instanceof List) {
      for (Object item : (List<?>) data.get("currencies")) {
        if (item instanceof String) {
          currencies.add((String) item);
        }
      }
    }
    return new Country(
        Data.string(data, "country"),
        Data.nullableString(data, "name"),
        Data.nullableString(data, "dial_code"),
        currencies,
        limits,
        Data.bool(data, "default", false));
  }
}
