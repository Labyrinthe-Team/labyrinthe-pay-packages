package cd.labyrinthe.labpay;

import java.util.Locale;
import java.util.Map;
import java.util.Optional;

/**
 * Status and headers of a response received from the API ({@link LabPayClient#lastResponse()}).
 *
 * @param status HTTP status
 * @param headers response headers, lower-case names
 */
public record ResponseInfo(int status, Map<String, String> headers) {

  /**
   * Creates the response information.
   *
   * @param status HTTP status
   * @param headers response headers, lower-case names
   */
  public ResponseInfo {
    headers = Map.copyOf(headers);
  }

  /**
   * One header.
   *
   * @param name header name (any case)
   * @return the value, if sent
   */
  public Optional<String> header(String name) {
    return Optional.ofNullable(headers.get(name.toLowerCase(Locale.ROOT)));
  }

  /**
   * Mode of the key that made the request ({@code X-Labyrinthe-Mode}).
   *
   * @return the mode, empty when the API did not send it
   */
  public Optional<Mode> mode() {
    return header("x-labyrinthe-mode").map(Mode::fromApi).filter(m -> m != Mode.UNKNOWN);
  }
}
