package cd.labyrinthe.labpay.spring;

import static org.assertj.core.api.Assertions.assertThat;
import static org.junit.jupiter.api.Assumptions.assumeTrue;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import cd.labyrinthe.labpay.LabPayClient;
import cd.labyrinthe.labpay.PaymentStatus;
import cd.labyrinthe.labpay.webhook.WebhookEvent;
import cd.labyrinthe.labpay.webhook.Webhooks;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import org.junit.jupiter.api.Test;
import org.springframework.boot.autoconfigure.AutoConfigurations;
import org.springframework.boot.autoconfigure.http.HttpMessageConvertersAutoConfiguration;
import org.springframework.boot.autoconfigure.web.servlet.DispatcherServletAutoConfiguration;
import org.springframework.boot.autoconfigure.web.servlet.WebMvcAutoConfiguration;
import org.springframework.boot.test.context.runner.ApplicationContextRunner;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.event.EventListener;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;

class LabPayAutoConfigurationTest {

  private static final String SECRET = "whsec_spring_test";

  private final ApplicationContextRunner runner =
      new ApplicationContextRunner()
          .withConfiguration(AutoConfigurations.of(LabPayAutoConfiguration.class));

  private final WebApplicationContextRunner web =
      new WebApplicationContextRunner()
          .withConfiguration(
              AutoConfigurations.of(
                  LabPayAutoConfiguration.class,
                  DispatcherServletAutoConfiguration.class,
                  WebMvcAutoConfiguration.class,
                  HttpMessageConvertersAutoConfiguration.class))
          .withUserConfiguration(Listener.class);

  /** Collecte les événements publiés. */
  @Configuration(proxyBeanMethods = false)
  static class Listener {
    final List<WebhookEvent> events = new CopyOnWriteArrayList<>();

    @EventListener
    void on(WebhookEvent event) {
      events.add(event);
    }
  }

  private static String notification(String event, String status) {
    return "{\"event\":\""
        + event
        + "\",\"payment\":{\"id\":\"DEP-1\",\"reference\":\"ORDER-42\",\"operation\":\"deposit\","
        + "\"livemode\":false,\"status\":\""
        + status
        + "\",\"gateway_status\":null,\"amount\":\"1000.00\",\"currency\":\"CDF\"},"
        + "\"sent_at\":\"2026-11-11T10:00:43+00:00\"}";
  }

  private static String sign(String body, String secret, long timestamp) {
    return Webhooks.sign(body.getBytes(StandardCharsets.UTF_8), List.of(secret), timestamp);
  }

  @Test
  void createsTheClientFromProperties() {
    runner
        .withPropertyValues(
            "labpay.api-key=test_spring_key",
            "labpay.base-url=http://localhost:8000",
            "labpay.timeout=5s",
            "labpay.max-retries=0",
            "labpay.auto-reference=false")
        .run(
            context -> {
              assertThat(context).hasSingleBean(LabPayClient.class);
              LabPayClient client = context.getBean(LabPayClient.class);
              assertThat(client.isTestMode()).isTrue();
              assertThat(client.baseUrl()).isEqualTo("http://localhost:8000/api/V2");
              assertThat(context.getBean(LabPayProperties.class).toString())
                  .doesNotContain("test_spring_key");
            });
  }

  @Test
  void noKeyMeansNoClient() {
    assumeTrue(System.getenv("LABPAY_API_KEY") == null, "LABPAY_API_KEY is set");
    runner.run(context -> assertThat(context).doesNotHaveBean(LabPayClient.class));
  }

  @Test
  void invalidPropertiesFailTheStartup() {
    runner
        .withPropertyValues("labpay.api-key=test_x", "labpay.max-retries=42")
        .run(context -> assertThat(context).hasFailed());
  }

  @Test
  void appliesCustomizersAndBacksOffForAUserClient() {
    runner
        .withPropertyValues("labpay.api-key=test_spring")
        .withBean(
            LabPayClientBuilderCustomizer.class,
            () -> builder -> builder.baseUrl("https://custom.test"))
        .run(
            context ->
                assertThat(context.getBean(LabPayClient.class).baseUrl())
                    .isEqualTo("https://custom.test/api/V2"));
    LabPayClient mine = LabPayClient.builder().apiKey("live_mine_token").build();
    runner
        .withPropertyValues("labpay.api-key=test_spring")
        .withBean(LabPayClient.class, () -> mine)
        .run(context -> assertThat(context.getBean(LabPayClient.class)).isSameAs(mine));
  }

  @Test
  void webhookEndpointOnlyWithASecretInAWebApplication() {
    assumeTrue(System.getenv("LABPAY_WEBHOOK_SECRET") == null, "LABPAY_WEBHOOK_SECRET is set");
    web.run(context -> assertThat(context).doesNotHaveBean(LabPayWebhookController.class));
    web.withPropertyValues("labpay.webhook.secret=" + SECRET, "labpay.webhook.enabled=false")
        .run(context -> assertThat(context).doesNotHaveBean(LabPayWebhookController.class));
    runner
        .withPropertyValues("labpay.webhook.secret=" + SECRET)
        .run(context -> assertThat(context).doesNotHaveBean(LabPayWebhookController.class));
    web.withPropertyValues("labpay.webhook.secret=" + SECRET)
        .run(context -> assertThat(context).hasSingleBean(LabPayWebhookController.class));
  }

  @Test
  void verifiesAndPublishesNotifications() {
    web.withPropertyValues(
            "labpay.webhook.secret=whsec_other," + SECRET, "labpay.webhook.path=/hooks/labpay")
        .run(
            context -> {
              MockMvc mvc = MockMvcBuilders.webAppContextSetup(context).build();
              Listener listener = context.getBean(Listener.class);
              long now = Instant.now().getEpochSecond();
              String body = notification("payment.completed", "completed");

              mvc.perform(
                      post("/hooks/labpay")
                          .contentType("application/json")
                          .header(Webhooks.SIGNATURE_HEADER, sign(body, SECRET, now))
                          .content(body))
                  .andExpect(status().isOk())
                  .andExpect(content().json("{\"received\":true}"));
              assertThat(listener.events).hasSize(1);
              WebhookEvent event = listener.events.get(0);
              assertThat(event.isPaymentCompleted()).isTrue();
              assertThat(event.payment().status()).isEqualTo(PaymentStatus.COMPLETED);

              // Signature fausse, absente, corps modifié, horodatage expiré : 400, rien de publié.
              mvc.perform(
                      post("/hooks/labpay")
                          .contentType("application/json")
                          .header(Webhooks.SIGNATURE_HEADER, sign(body, "wrong", now))
                          .content(body))
                  .andExpect(status().isBadRequest())
                  .andExpect(content().json("{\"error\":\"SIGNATURE_VERIFICATION_FAILED\"}"));
              mvc.perform(post("/hooks/labpay").contentType("application/json").content(body))
                  .andExpect(status().isBadRequest());
              mvc.perform(
                      post("/hooks/labpay")
                          .contentType("application/json")
                          .header(Webhooks.SIGNATURE_HEADER, sign(body, SECRET, now))
                          .content(body.replace("1000.00", "9000.00")))
                  .andExpect(status().isBadRequest());
              mvc.perform(
                      post("/hooks/labpay")
                          .contentType("application/json")
                          .header(Webhooks.SIGNATURE_HEADER, sign(body, SECRET, now - 3600))
                          .content(body))
                  .andExpect(status().isBadRequest());
              String notANotification = "[1,2,3]";
              mvc.perform(
                      post("/hooks/labpay")
                          .contentType("application/json")
                          .header(Webhooks.SIGNATURE_HEADER, sign(notANotification, SECRET, now))
                          .content(notANotification))
                  .andExpect(status().isBadRequest())
                  .andExpect(content().json("{\"error\":\"INVALID_WEBHOOK_PAYLOAD\"}"));
              assertThat(listener.events).hasSize(1);
            });
  }

  @Test
  void usesTheDefaultPath() {
    web.withPropertyValues("labpay.webhook.secret=" + SECRET)
        .run(
            context -> {
              MockMvc mvc = MockMvcBuilders.webAppContextSetup(context).build();
              String body = notification("payment.failed", "failed");
              mvc.perform(
                      post("/webhooks/labpay")
                          .contentType("application/json")
                          .header(
                              Webhooks.SIGNATURE_HEADER,
                              sign(body, SECRET, Instant.now().getEpochSecond()))
                          .content(body))
                  .andExpect(status().isOk());
              assertThat(context.getBean(Listener.class).events.get(0).isPaymentFailed()).isTrue();
            });
  }
}
