package cd.labyrinthe.labpay;

import java.io.IOException;
import java.io.InputStream;
import java.util.Properties;

/** Version of the SDK. */
public final class Version {

  /** Version of this SDK, e.g. {@code "0.1.0"} (from the Maven build). */
  public static final String VERSION = load();

  /** Version of the LabPay API covered by this SDK (path prefix {@code /V2}). */
  public static final String API_VERSION = "V2";

  private Version() {}

  /**
   * The {@code User-Agent} sent with every request.
   *
   * @return e.g. {@code "labpay-java/0.1.0 Java/21.0.4"}
   */
  public static String userAgent() {
    return "labpay-java/" + VERSION + " Java/" + System.getProperty("java.version", "unknown");
  }

  private static String load() {
    try (InputStream in = Version.class.getResourceAsStream("version.properties")) {
      if (in == null) {
        return "unknown";
      }
      Properties properties = new Properties();
      properties.load(in);
      String version = properties.getProperty("version", "unknown").trim();
      return version.isEmpty() || version.startsWith("${") ? "unknown" : version;
    } catch (IOException e) {
      return "unknown";
    }
  }
}
