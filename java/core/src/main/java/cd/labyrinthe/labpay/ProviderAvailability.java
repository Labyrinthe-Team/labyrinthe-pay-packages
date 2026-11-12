package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Real-time availability of a provider ({@code GET /V2/availability}).
 *
 * @param provider provider code
 * @param country ISO alpha-3 country, or null
 * @param statuses per operation type, e.g. {@code {DEPOSIT=OPERATIONAL, PAYOUT=DELAYED}} ({@code
 *     OPERATIONAL}, {@code DELAYED}: payouts queued, {@code CLOSED}); a value may be null
 */
public record ProviderAvailability(String provider, String country, Map<String, String> statuses) {

  /**
   * Creates the availability.
   *
   * @param provider provider code
   * @param country ISO alpha-3 country, or null
   * @param statuses per operation type
   */
  public ProviderAvailability {
    statuses = Collections.unmodifiableMap(new LinkedHashMap<>(statuses));
  }

  static ProviderAvailability fromJson(String provider, Object value) {
    Map<String, Object> data = Data.lenientObject(value);
    Map<String, String> statuses = new LinkedHashMap<>();
    data.forEach(
        (type, status) -> {
          if (!"country".equals(type)) {
            statuses.put(type, status instanceof String ? (String) status : null);
          }
        });
    return new ProviderAvailability(provider, Data.nullableString(data, "country"), statuses);
  }
}
