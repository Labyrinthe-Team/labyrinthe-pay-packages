package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Countries, providers, availability and prediction ({@code client.providers()}). */
public final class ProviderService extends Resource {

  private static final List<String> OPERATIONS = List.of("deposit", "payout", "refund");

  ProviderService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * Countries open to payments: dial code, currencies, limits per currency.
   *
   * @return the countries
   */
  public List<Country> countries() {
    Object data = transport.request("GET", "/countries", RetryMode.SAFE, null, null, false).data();
    List<Country> countries = new ArrayList<>();
    for (Object item : Data.array(data, "data")) {
      countries.add(Country.fromJson(item));
    }
    return Collections.unmodifiableList(countries);
  }

  /**
   * Providers and their rules for an operation.
   *
   * @param operation deposit (API default when null), payout or refund
   * @param country ISO alpha-3 country, or null for every country
   * @return the providers
   */
  public List<Provider> list(Operation operation, String country) {
    Map<String, Object> query = new LinkedHashMap<>();
    query.put(
        "operation",
        operation == null ? null : Input.oneOf(operation.value(), OPERATIONS, "operation"));
    query.put("country", Input.country(country));
    Object data = transport.request("GET", "/providers", RetryMode.SAFE, query, null, false).data();
    List<Provider> providers = new ArrayList<>();
    for (Object item : Data.array(data, "data")) {
      providers.add(Provider.fromJson(item));
    }
    return Collections.unmodifiableList(providers);
  }

  /**
   * Providers and their rules for deposits, in every country.
   *
   * @return the providers
   */
  public List<Provider> list() {
    return list(null, null);
  }

  /**
   * Real-time availability per provider code: {@code OPERATIONAL}, {@code DELAYED} (payouts queued)
   * or {@code CLOSED}.
   *
   * @param country ISO alpha-3 country, or null for every country
   * @return availability per provider code
   */
  public Map<String, ProviderAvailability> availability(String country) {
    Map<String, Object> query = new LinkedHashMap<>();
    query.put("country", Input.country(country));
    Object data =
        transport.request("GET", "/availability", RetryMode.SAFE, query, null, false).data();
    Map<String, ProviderAvailability> result = new LinkedHashMap<>();
    Data.object(data == null ? Collections.emptyMap() : data, "data")
        .forEach(
            (provider, statuses) ->
                result.put(provider, ProviderAvailability.fromJson(provider, statuses)));
    return Collections.unmodifiableMap(result);
  }

  /**
   * Real-time availability of every provider.
   *
   * @return availability per provider code
   */
  public Map<String, ProviderAvailability> availability() {
    return availability(null);
  }

  /**
   * Validates a number and predicts its provider, without creating a payment.
   *
   * @param phone local or international number
   * @param country ISO alpha-3 country, or null
   * @return the prediction
   * @throws cd.labyrinthe.labpay.exception.InvalidPhoneNumberException when the number is not valid
   */
  public ProviderPrediction predict(String phone, String country) {
    Map<String, Object> body = new LinkedHashMap<>();
    body.put("phone", Input.phone(phone));
    putIfPresent(body, "country", Input.country(country));
    Object data =
        transport.request("POST", "/predict-provider", RetryMode.SAFE, null, body, false).data();
    return ProviderPrediction.fromJson(data);
  }

  /**
   * Validates a number of the default country and predicts its provider.
   *
   * @param phone local or international number
   * @return the prediction
   */
  public ProviderPrediction predict(String phone) {
    return predict(phone, null);
  }
}
