package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.Map;

/**
 * A validated number and its predicted provider ({@code POST /V2/predict-provider}).
 *
 * @param phone international format without "+", e.g. {@code 243813456789}
 * @param provider provider code
 * @param country ISO alpha-3 country
 */
public record ProviderPrediction(String phone, String provider, String country) {

  static ProviderPrediction fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "data");
    return new ProviderPrediction(
        Data.string(data, "phone"), Data.string(data, "provider"), Data.string(data, "country"));
  }
}
