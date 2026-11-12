package cd.labyrinthe.labpay.exception;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Optional;

/**
 * The API answered with an error ({@code {"message", "code", "errors"?}}).
 *
 * <p>Subclasses exist for the codes an integration usually handles; any other code (including codes
 * added later) is thrown as a plain {@code ApiException}.
 */
public class ApiException extends LabPayException {

  private static final long serialVersionUID = 1L;

  // transient : Map d'interface, non sérialisable en Java ; vides après désérialisation.
  private final transient Map<String, List<String>> errors;
  private final transient Map<String, String> headers;

  /**
   * Creates the exception (normally done by the SDK).
   *
   * @param message human-readable message (in French when it comes from the API)
   * @param code stable machine-readable code
   * @param statusCode HTTP status
   * @param errors per-field messages
   * @param headers response headers (lower-case names)
   */
  public ApiException(
      String message,
      String code,
      int statusCode,
      Map<String, List<String>> errors,
      Map<String, String> headers) {
    super(message, code, statusCode);
    Map<String, List<String>> copy = new LinkedHashMap<>();
    if (errors != null) {
      errors.forEach((field, list) -> copy.put(field, List.copyOf(new ArrayList<>(list))));
    }
    this.errors = Collections.unmodifiableMap(copy);
    Map<String, String> lower = new LinkedHashMap<>();
    if (headers != null) {
      headers.forEach((name, value) -> lower.put(name.toLowerCase(Locale.ROOT), value));
    }
    this.headers = Collections.unmodifiableMap(lower);
  }

  /**
   * Per-field messages of a validation error, e.g. {@code {"amount": ["…"]}}.
   *
   * @return unmodifiable map, empty when there are none
   */
  public Map<String, List<String>> errors() {
    return errors == null ? Collections.emptyMap() : errors;
  }

  /**
   * Response headers.
   *
   * @return unmodifiable map with lower-case names
   */
  public Map<String, String> headers() {
    return headers == null ? Collections.emptyMap() : headers;
  }

  /**
   * One response header.
   *
   * @param name header name (any case)
   * @return the value, if sent
   */
  public Optional<String> header(String name) {
    return Optional.ofNullable(headers().get(name.toLowerCase(Locale.ROOT)));
  }

  /**
   * Mode of the key that made the request ({@code X-Labyrinthe-Mode}), when the API sent it.
   *
   * @return {@code "live"} or {@code "test"}
   */
  public Optional<String> mode() {
    return header("x-labyrinthe-mode").filter(m -> m.equals("live") || m.equals("test"));
  }

  /**
   * Seconds from the {@code Retry-After} header.
   *
   * @return the seconds, empty when the API does not say
   */
  public Optional<Long> retryAfter() {
    return header("retry-after")
        .map(String::trim)
        .filter(v -> v.matches("[0-9]{1,9}"))
        .map(Long::parseLong);
  }
}
