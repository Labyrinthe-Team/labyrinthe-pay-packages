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

