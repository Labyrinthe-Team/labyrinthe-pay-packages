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
