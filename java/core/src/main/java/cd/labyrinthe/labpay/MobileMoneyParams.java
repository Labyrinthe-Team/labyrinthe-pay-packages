package cd.labyrinthe.labpay;

import java.math.BigDecimal;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Parameters of a deposit ({@code POST /V2/deposits}) or a payout ({@code POST /V2/payouts}).
 * Immutable; checked by the SDK before any request.
 *
 * <pre>{@code
 * MobileMoneyParams params =
 *     MobileMoneyParams.builder()
 *         .amount("1000")
 *         .currency("CDF")
 *         .phone("0813456789")
 *         .reference("ORDER-42")
 *         .callbackUrl("https://shop.example/webhooks/labpay")
 *         .build();
 * }</pre>
 */
public final class MobileMoneyParams {

  private final Object amount;
  private final String currency;
  private final String phone;
  private final String country;
  private final String provider;
  private final String reference;
  private final String callbackUrl;
  private final String customerMessage;
  private final Map<String, Object> metadata;

  private MobileMoneyParams(Builder builder) {
    this.amount = builder.amount;
    this.currency = builder.currency;
    this.phone = builder.phone;
    this.country = builder.country;
    this.provider = builder.provider;
    this.reference = builder.reference;
    this.callbackUrl = builder.callbackUrl;
    this.customerMessage = builder.customerMessage;
    this.metadata =
        builder.metadata == null
            ? null
            : Collections.unmodifiableMap(new LinkedHashMap<>(builder.metadata));
  }

  /**
   * A new builder.
   *
   * @return the builder
   */
  public static Builder builder() {
    return new Builder();
  }

  /**
   * Shortcut for the three required fields.
   *
   * @param amount net amount, e.g. {@code "1500"} or {@code "12.50"}
   * @param currency {@code CDF} or {@code USD}
   * @param phone local ({@code 0812345678}) or international ({@code +243812345678}) number
   * @return the parameters
   */
  public static MobileMoneyParams of(String amount, String currency, String phone) {
    return builder().amount(amount).currency(currency).phone(phone).build();
  }

  /**
   * The amount, as given.
   *
   * @return a {@code String} or a {@code BigDecimal}
   */
  public Object amount() {
    return amount;
  }

  /**
   * The currency.
   *
   * @return the currency
   */
  public String currency() {
    return currency;
  }

  /**
   * The phone number.
   *
   * @return the number
   */
  public String phone() {
    return phone;
  }

  /**
   * The country, or null.
   *
   * @return the country
   */
  public String country() {
    return country;
  }

  /**
   * The provider code, or null.
   *
   * @return the provider
   */
  public String provider() {
    return provider;
  }

  /**
   * Your reference, or null.
   *
   * @return the reference
   */
  public String reference() {
    return reference;
  }

  /**
   * The notification URL, or null.
   *
   * @return the URL
   */
  public String callbackUrl() {
    return callbackUrl;
  }

  /**
   * The text shown to the customer, or null.
   *
   * @return the message
   */
  public String customerMessage() {
    return customerMessage;
  }

  /**
   * Your metadata, or null.
   *
   * @return unmodifiable metadata
   */
  public Map<String, Object> metadata() {
    return metadata;
  }

  /** The phone number is not shown. */
  @Override
  public String toString() {
    return "MobileMoneyParams(amount="
        + amount
        + ", currency="
        + currency
        + ", reference="
        + reference
        + ")";
  }

  /** Builder of {@link MobileMoneyParams}. Not thread-safe. */
