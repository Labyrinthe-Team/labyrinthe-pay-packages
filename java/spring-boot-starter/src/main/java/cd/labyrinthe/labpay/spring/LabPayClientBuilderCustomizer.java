package cd.labyrinthe.labpay.spring;

import cd.labyrinthe.labpay.LabPayClient;

/**
 * Customises the {@link LabPayClient.Builder} used for the auto-configured client, after the {@code
 * labpay.*} properties were applied (e.g. to pass your own {@code HttpClient}).
 *
 * <pre>{@code
 * @Bean
 * LabPayClientBuilderCustomizer proxy() {
 *   return builder -> builder.httpClient(HttpClient.newBuilder().proxy(myProxy).build());
 * }
 * }</pre>
 */
@FunctionalInterface
public interface LabPayClientBuilderCustomizer {

  /**
   * Customises the builder.
   *
   * @param builder the builder, already configured from the properties
   */
  void customize(LabPayClient.Builder builder);
}
