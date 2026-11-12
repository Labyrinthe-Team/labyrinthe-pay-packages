package cd.labyrinthe.labpay.spring;

import java.time.Duration;
import org.springframework.boot.context.properties.ConfigurationProperties;

/**
 * Configuration of the LabPay client and notification endpoint ({@code labpay.*}).
 *
 * <pre>
 * labpay.api-key=${LABPAY_API_KEY}
 * labpay.webhook.secret=${LABPAY_WEBHOOK_SECRET}
 * </pre>
 */
@ConfigurationProperties(prefix = "labpay")
public class LabPayProperties {

  /**
   * Live token or test key ({@code test_…}). Defaults to the {@code LABPAY_API_KEY} environment
   * variable. The client bean is only created when a key is available.
   */
  private String apiKey;

  /** API root ({@code /V2} is added). Defaults to {@code LABPAY_BASE_URL}, then to production. */
  private String baseUrl;

  /** Timeout of each attempt. */
  private Duration timeout = Duration.ofSeconds(30);

  /** Automatic retries, only when they are safe (0 to 10). */
  private int maxRetries = 2;

  /** Longest Retry-After the SDK waits for before giving up. */
  private Duration maxRetryWait = Duration.ofSeconds(10);

  /** Generate a reference (sdk_uuid) for payments sent without one, so they can be retried. */
  private boolean autoReference = true;

  /** Signed notification endpoint. */
  private final Webhook webhook = new Webhook();

  /**
   * @return the API key
   */
  public String getApiKey() {
    return apiKey;
  }

  /**
   * @param apiKey the API key
   */
  public void setApiKey(String apiKey) {
    this.apiKey = apiKey;
  }

  /**
   * @return the API root
   */
  public String getBaseUrl() {
    return baseUrl;
  }

  /**
   * @param baseUrl the API root
   */
  public void setBaseUrl(String baseUrl) {
    this.baseUrl = baseUrl;
  }

  /**
   * @return the timeout of each attempt
   */
  public Duration getTimeout() {
    return timeout;
  }

  /**
   * @param timeout the timeout of each attempt
   */
  public void setTimeout(Duration timeout) {
    this.timeout = timeout;
  }

  /**
   * @return the number of automatic retries
   */
  public int getMaxRetries() {
    return maxRetries;
  }

  /**
   * @param maxRetries the number of automatic retries
   */
  public void setMaxRetries(int maxRetries) {
    this.maxRetries = maxRetries;
  }

  /**
   * @return the longest wait accepted before a retry
   */
  public Duration getMaxRetryWait() {
    return maxRetryWait;
  }

  /**
   * @param maxRetryWait the longest wait accepted before a retry
   */
  public void setMaxRetryWait(Duration maxRetryWait) {
    this.maxRetryWait = maxRetryWait;
  }

  /**
   * @return whether references are generated
   */
  public boolean isAutoReference() {
    return autoReference;
  }

  /**
   * @param autoReference whether references are generated
   */
  public void setAutoReference(boolean autoReference) {
    this.autoReference = autoReference;
  }

  /**
   * @return the notification endpoint settings
   */
  public Webhook getWebhook() {
    return webhook;
  }

  /** Settings of the signed notification endpoint ({@code labpay.webhook.*}). */
  public static class Webhook {

    /** Whether the notification endpoint is registered (when a secret is set). */
    private boolean enabled = true;

    /** Path of the notification endpoint (POST), to use as callback_url. */
    private String path = "/webhooks/labpay";

    /**
     * Signing secret (whsec_…, GET /V2/account with the live token); several secrets may be
     * separated by commas. Defaults to {@code LABPAY_WEBHOOK_SECRET}. The endpoint is only
     * registered when a secret is available.
     */
    private String secret;

    /** Maximum age of the signed timestamp (replay protection); 0 disables the check. */
    private Duration tolerance = Duration.ofSeconds(300);

    /**
     * @return whether the endpoint is enabled
     */
    public boolean isEnabled() {
      return enabled;
    }

    /**
     * @param enabled whether the endpoint is enabled
     */
    public void setEnabled(boolean enabled) {
      this.enabled = enabled;
    }

    /**
     * @return the endpoint path
     */
    public String getPath() {
      return path;
    }

    /**
     * @param path the endpoint path
     */
    public void setPath(String path) {
      this.path = path;
    }

    /**
     * @return the signing secret(s)
     */
    public String getSecret() {
      return secret;
    }

    /**
     * @param secret the signing secret(s), comma-separated
     */
    public void setSecret(String secret) {
      this.secret = secret;
    }

    /**
     * @return the timestamp tolerance
     */
    public Duration getTolerance() {
      return tolerance;
    }

    /**
     * @param tolerance the timestamp tolerance
     */
    public void setTolerance(Duration tolerance) {
      this.tolerance = tolerance;
    }

    /** The secret is never shown. */
    @Override
    public String toString() {
      return "Webhook(enabled="
          + enabled
          + ", path="
          + path
          + ", secret="
          + (secret == null ? "unset" : "****")
          + ", tolerance="
          + tolerance
          + ")";
    }
  }

  /** The API key is never shown. */
  @Override
  public String toString() {
    return "LabPayProperties(apiKey="
        + (apiKey == null ? "unset" : "****")
        + ", baseUrl="
        + baseUrl
        + ", webhook="
        + webhook
        + ")";
  }
}
