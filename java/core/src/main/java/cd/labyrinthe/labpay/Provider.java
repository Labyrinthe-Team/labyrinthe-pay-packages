package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * A provider and its rules for an operation ({@code GET /V2/providers}).
 *
 * @param provider provider code, to pass as {@code provider}, e.g. {@code VODACOM_MPESA_COD}
 * @param country ISO alpha-3 country, or null
 * @param name name, or null
 * @param nameDisplayedToCustomer name shown to the customer, or null
 * @param logo logo URL, or null
 * @param currencies rules per currency
 */
public record Provider(
    String provider,
    String country,
    String name,
    String nameDisplayedToCustomer,
    String logo,
    Map<String, ProviderCurrency> currencies) {

  /**
   * Creates a provider.
   *
   * @param provider provider code
   * @param country ISO alpha-3 country, or null
   * @param name name, or null
   * @param nameDisplayedToCustomer name shown to the customer, or null
   * @param logo logo URL, or null
   * @param currencies rules per currency
   */
  public Provider {
    currencies = Collections.unmodifiableMap(new LinkedHashMap<>(currencies));
  }

  static Provider fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "provider");
    Map<String, ProviderCurrency> currencies = new LinkedHashMap<>();
    Data.optionalObject(data, "currencies")
        .forEach((currency, rules) -> currencies.put(currency, ProviderCurrency.fromJson(rules)));
    return new Provider(
        Data.string(data, "provider"),
        Data.nullableString(data, "country"),
        Data.nullableString(data, "name"),
        Data.nullableString(data, "name_displayed_to_customer"),
        Data.nullableString(data, "logo"),
        currencies);
  }
}
