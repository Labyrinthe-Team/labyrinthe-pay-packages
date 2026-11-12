package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Json;
import cd.labyrinthe.labpay.internal.Transport;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayDeque;
import java.util.Collections;
import java.util.Deque;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Serveur HTTP simulé (JDK) : réponses en file, requêtes enregistrées. */
final class MockApi implements AutoCloseable {

  static final String KEY = "test_abcdefghijklmnop";

  /** Une réponse programmée. */
  static final class Reply {
    final int status;
    final String body;
    final Map<String, String> headers = new LinkedHashMap<>();
    long delayMillis;
    boolean disconnect;

    Reply(int status, String body) {
      this.status = status;
      this.body = body;
    }

    Reply header(String name, String value) {
      headers.put(name, value);
      return this;
    }

    Reply delay(long millis) {
      this.delayMillis = millis;
      return this;
    }
  }

  /** Une requête reçue. */
  static final class Recorded {
    final String method;
    final String path;
    final String rawQuery;
    final Map<String, String> headers;
    final String body;

    Recorded(
        String method, String path, String rawQuery, Map<String, String> headers, String body) {
      this.method = method;
      this.path = path;
      this.rawQuery = rawQuery;
      this.headers = headers;
      this.body = body;
    }

    String header(String name) {
      return headers.get(name.toLowerCase(Locale.ROOT));
    }

    Map<String, String> query() {
      Map<String, String> query = new LinkedHashMap<>();
      if (rawQuery != null) {
        for (String pair : rawQuery.split("&")) {
          String[] parts = pair.split("=", 2);
          query.put(
              URLDecoder.decode(parts[0], StandardCharsets.UTF_8),
              parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "");
        }
      }
      return query;
    }

    @SuppressWarnings("unchecked")
    Map<String, Object> json() {
      return (Map<String, Object>) Json.parse(body);
    }
  }

  /** Attente de test : enregistre les délais sans attendre. */
  static final class RecordingSleeper implements Transport.Sleeper {
    final List<Duration> sleeps = new CopyOnWriteArrayList<>();

    @Override
    public void sleep(Duration delay) {
      sleeps.add(delay);
    }
  }

  private final HttpServer server;
  private final ExecutorService executor = Executors.newCachedThreadPool();
  private final Deque<Reply> replies = new ArrayDeque<>();
  final List<Recorded> requests = new CopyOnWriteArrayList<>();
  final RecordingSleeper sleeper = new RecordingSleeper();

  MockApi() {
    try {
      server = HttpServer.create(new InetSocketAddress(InetAddress.getLoopbackAddress(), 0), 0);
    } catch (IOException e) {
      throw new IllegalStateException(e);
    }
    server.setExecutor(executor);
    server.createContext("/", this::handle);
    server.start();
  }

  // -- fabrique de réponses -----------------------------------------------------------------------

  static Reply json(int status, String body) {
    return new Reply(status, body)
        .header("Content-Type", "application/json")
        .header("X-Labyrinthe-Mode", "test");
  }

  static Reply data(int status, String dataJson) {
    return json(status, "{\"data\":" + dataJson + "}");
  }

  static Reply error(int status, String code) {
    return json(status, "{\"message\":\"Message en français\",\"code\":\"" + code + "\"}");
  }

  static Reply raw(int status, String body) {
    return new Reply(status, body);
  }

  static Reply disconnect() {
    Reply reply = new Reply(0, "");
    reply.disconnect = true;
    return reply;
  }

  /** Paiement de l'API ; {@code overrides} remplace des champs (JSON brut). */
  static String payment(String... overrides) {
    Map<String, String> fields = new LinkedHashMap<>();
    fields.put("id", "\"DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6\"");
    fields.put("reference", "\"ORDER-42\"");
    fields.put("operation", "\"deposit\"");
    fields.put("livemode", "false");
    fields.put("status", "\"pending\"");
    fields.put("gateway_status", "\"ACCEPTED\"");
    fields.put("amount", "\"1000.00\"");
    fields.put("fee", "\"35.00\"");
    fields.put("total_amount", "\"1035.00\"");
    fields.put("currency", "\"CDF\"");
    fields.put("country", "\"COD\"");
    fields.put("phone", "\"0813456789\"");
    fields.put("provider", "\"VODACOM_MPESA_COD\"");
    fields.put("provider_transaction_id", "null");
    fields.put("failure", "null");
    fields.put("metadata", "{\"orderId\":\"42\"}");
    fields.put("created_at", "\"2026-11-11T10:00:00+00:00\"");
    fields.put("completed_at", "null");
    fields.put("refunded_amount", "\"0.00\"");
    for (int i = 0; i + 1 < overrides.length; i += 2) {
      fields.put(overrides[i], overrides[i + 1]);
    }
    StringBuilder out = new StringBuilder("{");
    fields.forEach(
        (k, v) -> {
          if (out.length() > 1) {
            out.append(',');
          }
          out.append('"').append(k).append("\":").append(v);
        });
    return out.append('}').toString();
  }

  // -- utilisation --------------------------------------------------------------------------------

  MockApi enqueue(Reply... list) {
    synchronized (replies) {
      Collections.addAll(replies, list);
    }
    return this;
  }

  String url() {
    return "http://127.0.0.1:" + server.getAddress().getPort();
  }

  LabPayClient.Builder builder() {
    return LabPayClient.builder()
        .apiKey(KEY)
        .baseUrl(url() + "/api")
        .environment(name -> null)
        .sleeper(sleeper);
  }

  LabPayClient client() {
    return builder().build();
  }

  Recorded last() {
    return requests.get(requests.size() - 1);
  }

  private void handle(HttpExchange exchange) throws IOException {
    String body;
    try (InputStream in = exchange.getRequestBody()) {
      body = new String(in.readAllBytes(), StandardCharsets.UTF_8);
    }
    Map<String, String> headers = new LinkedHashMap<>();
    exchange
        .getRequestHeaders()
        .forEach((name, values) -> headers.put(name.toLowerCase(Locale.ROOT), values.get(0)));
    requests.add(
        new Recorded(
            exchange.getRequestMethod(),
            exchange.getRequestURI().getRawPath(),
            exchange.getRequestURI().getRawQuery(),
            headers,
            body));
    Reply reply;
    synchronized (replies) {
      reply = replies.pollFirst();
    }
    if (reply == null) {
      reply = error(500, "NO_REPLY_QUEUED");
    }
    if (reply.delayMillis > 0) {
      try {
        Thread.sleep(reply.delayMillis);
      } catch (InterruptedException e) {
        Thread.currentThread().interrupt();
      }
    }
    if (reply.disconnect) {
      // Fermeture sans réponse : le client voit une erreur réseau.
      exchange.close();
      return;
    }
    byte[] bytes = reply.body.getBytes(StandardCharsets.UTF_8);
    reply.headers.forEach((name, value) -> exchange.getResponseHeaders().add(name, value));
    exchange.sendResponseHeaders(reply.status, bytes.length == 0 ? -1 : bytes.length);
    if (bytes.length > 0) {
      try (OutputStream out = exchange.getResponseBody()) {
        out.write(bytes);
      }
    }
    exchange.close();
  }

  @Override
  public void close() {
    server.stop(0);
    executor.shutdownNow();
  }
}
