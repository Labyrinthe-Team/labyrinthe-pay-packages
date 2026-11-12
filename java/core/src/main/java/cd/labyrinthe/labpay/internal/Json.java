package cd.labyrinthe.labpay.internal;

import java.math.BigDecimal;
import java.math.BigInteger;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Lecteur et écrivain JSON minimal (RFC 8259), sans dépendance. Usage interne.
 *
 * <p>Pourquoi pas Jackson : le SDK n'échange que de petits objets JSON simples ; un parseur interne
 * évite d'imposer une version de Jackson aux applications (conflits classiques avec Spring Boot) et
 * réduit la surface d'attaque. Les valeurs lues sont : {@code Map<String, Object>} (ordre conservé,
 * non modifiable), {@code List<Object>} (non modifiable), {@code String}, {@code Long} ou {@code
 * BigInteger} (entiers), {@code BigDecimal} (décimaux, valeur exacte : jamais de double pour un
 * montant), {@code Boolean} et {@code null}.
 */
public final class Json {

  /** Profondeur maximale d'imbrication acceptée (protection contre les entrées hostiles). */
  static final int MAX_DEPTH = 256;

  private final String text;
  private int pos;

  private Json(String text) {
    this.text = text;
  }

  /**
   * Décode un document JSON complet.
   *
   * @param text texte JSON
   * @return la valeur décodée
   * @throws JsonException si le texte n'est pas du JSON valide
   */
  public static Object parse(String text) {
    Json parser = new Json(text);
    parser.skipWhitespace();
    Object value = parser.readValue(0);
    parser.skipWhitespace();
    if (parser.pos != text.length()) {
      throw parser.error("unexpected trailing characters");
    }
    return value;
  }

  /**
   * Encode une valeur ({@code Map}, {@code Iterable}, tableau d'objets, {@code CharSequence},
   * {@code Number}, {@code Boolean}, {@code Enum} ou {@code null}) en JSON compact.
   *
   * @param value valeur à encoder
   * @return le texte JSON
   */
  public static String write(Object value) {
    StringBuilder out = new StringBuilder();
    writeValue(out, value, 0);
    return out.toString();
  }

  // -- lecture ------------------------------------------------------------------------------------

  private Object readValue(int depth) {
    if (depth > MAX_DEPTH) {
      throw error("nesting too deep");
    }
    if (pos >= text.length()) {
      throw error("unexpected end of input");
    }
    char c = text.charAt(pos);
    switch (c) {
      case '{':
        return readObject(depth);
      case '[':
        return readArray(depth);
      case '"':
        return readString();
      case 't':
        expectWord("true");
        return Boolean.TRUE;
      case 'f':
        expectWord("false");
        return Boolean.FALSE;
      case 'n':
        expectWord("null");
        return null;
      default:
        if (c == '-' || (c >= '0' && c <= '9')) {
          return readNumber();
        }
        throw error("unexpected character");
    }
  }

  private Map<String, Object> readObject(int depth) {
    pos++; // {
    Map<String, Object> map = new LinkedHashMap<>();
    skipWhitespace();
    if (peek() == '}') {
      pos++;
      return Collections.unmodifiableMap(map);
    }
    while (true) {
      skipWhitespace();
      if (peek() != '"') {
        throw error("expected a string key");
      }
      String key = readString();
      skipWhitespace();
      expect(':');
      skipWhitespace();
      map.put(key, readValue(depth + 1));
      skipWhitespace();
      char c = next();
      if (c == '}') {
        return Collections.unmodifiableMap(map);
      }
      if (c != ',') {
        throw error("expected ',' or '}'");
      }
    }
  }

  private List<Object> readArray(int depth) {
    pos++; // [
    List<Object> list = new ArrayList<>();
    skipWhitespace();
    if (peek() == ']') {
      pos++;
      return Collections.unmodifiableList(list);
    }
    while (true) {
      skipWhitespace();
      list.add(readValue(depth + 1));
      skipWhitespace();
      char c = next();
      if (c == ']') {
        return Collections.unmodifiableList(list);
      }
      if (c != ',') {
        throw error("expected ',' or ']'");
      }
    }
  }

  private String readString() {
    pos++; // "
    StringBuilder out = new StringBuilder();
    while (true) {
      if (pos >= text.length()) {
        throw error("unterminated string");
      }
      char c = text.charAt(pos++);
      if (c == '"') {
        return out.toString();
      }
      if (c < 0x20) {
        throw error("control character in string");
      }
      if (c != '\\') {
        out.append(c);
        continue;
      }
      char escape = next();
      switch (escape) {
        case '"':
        case '\\':
        case '/':
          out.append(escape);
          break;
        case 'b':
          out.append('\b');
          break;
        case 'f':
          out.append('\f');
          break;
        case 'n':
          out.append('\n');
          break;
        case 'r':
          out.append('\r');
          break;
        case 't':
          out.append('\t');
          break;
        case 'u':
          out.append(readHex4());
          break;
        default:
          throw error("invalid escape sequence");
      }
    }
  }

  private char readHex4() {
    if (pos + 4 > text.length()) {
      throw error("invalid unicode escape");
    }
    int value = 0;
    for (int i = 0; i < 4; i++) {
      int digit = Character.digit(text.charAt(pos++), 16);
      if (digit < 0) {
        throw error("invalid unicode escape");
      }
      value = value * 16 + digit;
    }
    return (char) value;
  }

