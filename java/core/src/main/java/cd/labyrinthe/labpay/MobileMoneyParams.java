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
   * @param currency currency of the number's country, e.g. {@code CDF}, {@code USD}, {@code XOF}
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
  public static final class Builder {
    private Object amount;
    private String currency;
    private String phone;
    private String country;
    private String provider;
    private String reference;
    private String callbackUrl;
    private String customerMessage;
    private Map<String, Object> metadata;

    private Builder() {}

    /**
     * Net amount: credited to you for a deposit (the customer pays it plus fees), received by the
     * recipient for a payout. Greater than 0, at most 2 decimals.
     *
     * @param amount e.g. {@code "1500"} or {@code "12.50"}
     * @return this builder
     */
    public Builder amount(String amount) {
      this.amount = amount;
      return this;
    }

    /**
     * Net amount as an exact decimal (at most 2 decimals).
     *
     * @param amount e.g. {@code new BigDecimal("12.50")}
     * @return this builder
     */
    public Builder amount(BigDecimal amount) {
      this.amount = amount;
      return this;
    }

    /**
     * Net amount as a whole number.
     *
     * @param amount e.g. {@code 1500}
     * @return this builder
     */
    public Builder amount(long amount) {
      this.amount = BigDecimal.valueOf(amount);
      return this;
    }

    /**
     * Currency.
     *
     * @param currency currency of the number's country, e.g. {@code CDF}, {@code USD}, {@code XOF}
     * @return this builder
     */
    public Builder currency(String currency) {
      this.currency = currency;
      return this;
    }

    /**
     * Phone number: local ({@code 0812345678}) or international ({@code +243812345678}), at most 20
     * characters.
     *
     * @param phone the number
     * @return this builder
     */
    public Builder phone(String phone) {
      this.phone = phone;
      return this;
    }

    /**
     * ISO alpha-3 country of the number (without it: a local number belongs to the platform's
     * default country, an international number to the country of its dial code).
     *
     * @param country e.g. {@code COD}
     * @return this builder
     */
    public Builder country(String country) {
      this.country = country;
      return this;
    }

    /**
     * Provider code (inferred from the number when absent).
     *
     * @param provider e.g. {@code VODACOM_MPESA_COD}
     * @return this builder
     */
    public Builder provider(String provider) {
      this.provider = provider;
      return this;
    }

    /**
     * Your reference, unique per token: 1 to 100 of {@code A-Z a-z 0-9 . _ : -}. Sending the same
     * reference again returns the existing payment (idempotency). Generated ({@code sdk_<uuid>})
     * when absent, unless {@code autoReference(false)}.
     *
     * @param reference e.g. {@code ORDER-42}
     * @return this builder
     */
    public Builder reference(String reference) {
      this.reference = reference;
      return this;
    }

    /**
     * Public HTTPS URL receiving the final status notification.
     *
     * @param callbackUrl e.g. {@code https://shop.example/webhooks/labpay}
     * @return this builder
     */
    public Builder callbackUrl(String callbackUrl) {
      this.callbackUrl = callbackUrl;
      return this;
    }

    /**
     * Text shown to the customer: 4 to 22 letters, digits, spaces.
     *
     * @param customerMessage e.g. {@code Commande 42}
     * @return this builder
     */
    public Builder customerMessage(String customerMessage) {
      this.customerMessage = customerMessage;
      return this;
    }

    /**
     * Up to 20 key/value pairs (strings of at most 255 characters; numbers and booleans are
     * converted), returned as is in the payment and the notifications.
     *
     * @param metadata the metadata
     * @return this builder
     */
    public Builder metadata(Map<String, ?> metadata) {
      this.metadata = metadata == null ? null : new LinkedHashMap<>(metadata);
      return this;
    }

    /**
     * Adds one metadata entry.
     *
     * @param key the key
     * @param value a string, number, boolean or null
     * @return this builder
     */
    public Builder metadata(String key, Object value) {
      if (this.metadata == null) {
        this.metadata = new LinkedHashMap<>();
      }
      this.metadata.put(key, value);
      return this;
    }

    /**
     * Builds the parameters (they are checked when sent).
     *
     * @return the parameters
     */
    public MobileMoneyParams build() {
      return new MobileMoneyParams(this);
    }
  }
}
