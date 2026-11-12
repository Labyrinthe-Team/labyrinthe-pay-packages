package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import cd.labyrinthe.labpay.internal.Redact;
import java.time.Instant;
import java.util.Map;

/**
 * The merchant account seen with the current key ({@code GET /V2/account}).
 *
 * @param mode mode of the key
 * @param merchant merchant name, or null
 * @param liveEnabled true once the token is enabled for production; otherwise live collections have
 *     a daily limit
 * @param kycVerified whether the merchant identity is verified
 * @param depositFeeRate deposit fee rate between 0 and 1 (e.g. {@code "0.0350"} = 3.5 %), or null
 * @param payoutFeeRate payout fee rate between 0 and 1, or null
 * @param testKey the test key ({@code test_...}), or null
 * @param webhookSecret signing secret ({@code whsec_...}); live token only, null with the test key
 * @param webhookSecretPreviousExpiresAt during a rotation: expiry of the previous secret, or null
 */
public record Account(
    Mode mode,
    String merchant,
    boolean liveEnabled,
    boolean kycVerified,
    String depositFeeRate,
    String payoutFeeRate,
    String testKey,
    String webhookSecret,
    Instant webhookSecretPreviousExpiresAt) {

  static Account fromJson(Object value) {
    Map<String, Object> data = Data.object(value, "account");
    Map<String, Object> fees = Data.optionalObject(data, "fees");
    return new Account(
        Mode.fromApi(Data.nullableString(data, "mode")),
        Data.nullableString(data, "merchant"),
        Data.bool(data, "live_enabled", false),
        Data.bool(data, "kyc_verified", false),
        Data.nullableString(fees, "deposit_rate"),
        Data.nullableString(fees, "payout_rate"),
        Data.nullableString(data, "test_key"),
        Data.nullableString(data, "webhook_secret"),
        Data.nullableInstant(data, "webhook_secret_previous_expires_at"));
  }

  /** The test key and the signing secret are masked. */
  @Override
  public String toString() {
    return "Account(mode="
        + mode
        + ", merchant="
        + merchant
        + ", liveEnabled="
        + liveEnabled
        + ", kycVerified="
        + kycVerified
        + ", testKey="
        + (testKey == null ? "null" : Redact.apiKey(testKey))
        + ", webhookSecret="
        + (webhookSecret == null ? "null" : "whsec_****")
        + ")";
  }
}
