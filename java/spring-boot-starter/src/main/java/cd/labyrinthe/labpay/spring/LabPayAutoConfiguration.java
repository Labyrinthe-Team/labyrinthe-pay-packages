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
      builder.apiKey(properties.getApiKey());
    }
    if (StringUtils.hasText(properties.getBaseUrl())) {
      builder.baseUrl(properties.getBaseUrl());
    }
    customizers.orderedStream().forEach(customizer -> customizer.customize(builder));
    return builder.build();
  }

  /** The notification endpoint (Spring MVC applications only). */
  @Configuration(proxyBeanMethods = false)
  @ConditionalOnWebApplication(type = ConditionalOnWebApplication.Type.SERVLET)
  @ConditionalOnClass(name = "org.springframework.web.servlet.DispatcherServlet")
  @Conditional(OnWebhookSecretCondition.class)
  static class WebhookConfiguration {

    @Bean
    @ConditionalOnMissingBean
    LabPayWebhookController labPayWebhookController(
        LabPayProperties properties, Environment environment, ApplicationEventPublisher publisher) {
      String secret = properties.getWebhook().getSecret();
      if (!StringUtils.hasText(secret)) {
        secret = environment.getProperty(ENV_WEBHOOK_SECRET);
      }
      return new LabPayWebhookController(secret, properties.getWebhook().getTolerance(), publisher);
    }
  }

  /** Une clé API est disponible : propriété {@code labpay.api-key} ou variable d'environnement. */
  static final class OnApiKeyCondition extends SpringBootCondition {
    @Override
    public ConditionOutcome getMatchOutcome(
        ConditionContext context, AnnotatedTypeMetadata metadata) {
      Environment env = context.getEnvironment();
      if (StringUtils.hasText(env.getProperty("labpay.api-key"))) {
        return ConditionOutcome.match("labpay.api-key is set");
      }
      if (StringUtils.hasText(env.getProperty(LabPayClient.ENV_API_KEY))) {
        return ConditionOutcome.match(LabPayClient.ENV_API_KEY + " is set");
      }
      return ConditionOutcome.noMatch("no LabPay API key");
    }
  }

  /** Un secret de signature est configuré et le point d'entrée n'est pas désactivé. */
  static final class OnWebhookSecretCondition extends SpringBootCondition {
    @Override
    public ConditionOutcome getMatchOutcome(
        ConditionContext context, AnnotatedTypeMetadata metadata) {
      Environment env = context.getEnvironment();
      if ("false".equalsIgnoreCase(env.getProperty("labpay.webhook.enabled"))) {
        return ConditionOutcome.noMatch("labpay.webhook.enabled is false");
      }
      if (StringUtils.hasText(env.getProperty("labpay.webhook.secret"))) {
        return ConditionOutcome.match("labpay.webhook.secret is set");
      }
      return StringUtils.hasText(env.getProperty(ENV_WEBHOOK_SECRET))
          ? ConditionOutcome.match(ENV_WEBHOOK_SECRET + " is set")
          : ConditionOutcome.noMatch("labpay.webhook.secret is not set");
    }
  }
}
