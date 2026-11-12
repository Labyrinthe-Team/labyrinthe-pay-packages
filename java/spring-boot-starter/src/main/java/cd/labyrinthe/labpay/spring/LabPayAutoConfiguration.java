package cd.labyrinthe.labpay.spring;

import cd.labyrinthe.labpay.LabPayClient;
import cd.labyrinthe.labpay.Version;
import org.springframework.beans.factory.ObjectProvider;
import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionOutcome;
import org.springframework.boot.autoconfigure.condition.ConditionalOnClass;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.autoconfigure.condition.ConditionalOnWebApplication;
import org.springframework.boot.autoconfigure.condition.SpringBootCondition;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.context.ApplicationEventPublisher;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.ConditionContext;
import org.springframework.context.annotation.Conditional;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.env.Environment;
import org.springframework.core.type.AnnotatedTypeMetadata;
import org.springframework.util.StringUtils;

/**
 * Auto-configuration of LabPay.
 *
 * <ul>
 *   <li>a {@link LabPayClient} bean, when {@code labpay.api-key} (or the {@code LABPAY_API_KEY}
 *       environment variable) is set and no client bean exists;
 *   <li>a signed notification endpoint ({@code POST labpay.webhook.path}, default {@code
 *       /webhooks/labpay}) in Spring MVC applications, when {@code labpay.webhook.secret} (or
 *       {@code LABPAY_WEBHOOK_SECRET}) is set. Each verified notification is published as a {@link
 *       cd.labyrinthe.labpay.webhook.WebhookEvent} application event.
 * </ul>
 */
@AutoConfiguration
@EnableConfigurationProperties(LabPayProperties.class)
public class LabPayAutoConfiguration {

  /** Environment variable read for the signing secret: {@value}. */
  public static final String ENV_WEBHOOK_SECRET = "LABPAY_WEBHOOK_SECRET";

  /** Creates the auto-configuration. */
  public LabPayAutoConfiguration() {}

  /**
   * The client, configured from {@code labpay.*}.
   *
   * @param properties the properties
   * @param customizers builder customizers, applied in order
   * @return the client
   */
  @Bean
  @ConditionalOnMissingBean
  @Conditional(OnApiKeyCondition.class)
  public LabPayClient labPayClient(
      LabPayProperties properties, ObjectProvider<LabPayClientBuilderCustomizer> customizers) {
    LabPayClient.Builder builder =
        LabPayClient.builder()
            .timeout(properties.getTimeout())
            .maxRetries(properties.getMaxRetries())
            .maxRetryWait(properties.getMaxRetryWait())
            .autoReference(properties.isAutoReference())
            .userAgentSuffix("labpay-spring-boot/" + Version.VERSION);
    if (StringUtils.hasText(properties.getApiKey())) {
