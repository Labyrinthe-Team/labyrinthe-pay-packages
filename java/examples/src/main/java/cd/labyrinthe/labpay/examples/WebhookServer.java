package cd.labyrinthe.labpay.examples;

import cd.labyrinthe.labpay.exception.InvalidWebhookPayloadException;
import cd.labyrinthe.labpay.exception.SignatureVerificationException;
import cd.labyrinthe.labpay.webhook.WebhookEvent;
import cd.labyrinthe.labpay.webhook.Webhooks;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.InputStream;
import java.net.InetSocketAddress;

/**
 * Receives the signed notifications with the JDK HTTP server only (no framework).
 *
 * <pre>
 * LABPAY_WEBHOOK_SECRET=whsec_… java cd.labyrinthe.labpay.examples.WebhookServer
 * </pre>
 *
 * <p>Then use {@code https://<your host>/webhooks/labpay} as {@code callback_url}.
 */
public final class WebhookServer {

  private WebhookServer() {}

  /**
   * Runs the example.
   *
   * @param args unused
   * @throws IOException if the port is taken
   */
  public static void main(String[] args) throws IOException {
    String secret = System.getenv("LABPAY_WEBHOOK_SECRET");
    HttpServer server = HttpServer.create(new InetSocketAddress(8080), 0);
    server.createContext("/webhooks/labpay", exchange -> handle(exchange, secret));
    server.start();
    System.out.println("Listening on http://localhost:8080/webhooks/labpay");
  }

  private static void handle(HttpExchange exchange, String secret) throws IOException {
    if (!"POST".equals(exchange.getRequestMethod())) {
      exchange.sendResponseHeaders(405, -1);
      exchange.close();
      return;
    }
    byte[] body;
    try (InputStream in = exchange.getRequestBody()) {
      body = in.readAllBytes(); // RAW body: never re-encode it before verifying
    }
    int status;
    try {
      WebhookEvent event =
          Webhooks.constructEvent(
              body, exchange.getRequestHeaders().getFirst(Webhooks.SIGNATURE_HEADER), secret);
      if (event.isPaymentCompleted()) {
        // Deliver the order once: deduplicate on X-Labyrinthe-Delivery or payment().id().
        System.out.println("Paid: " + event.payment().reference());
      } else if (event.isPaymentFailed()) {
        System.out.println("Failed: " + event.payment().failure());
      }
      status = 200;
    } catch (SignatureVerificationException | InvalidWebhookPayloadException e) {
      status = 400;
    }
    exchange.sendResponseHeaders(status, -1); // answer within 10 seconds
    exchange.close();
  }
}
