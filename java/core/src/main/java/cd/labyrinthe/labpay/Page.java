package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.function.Function;

/**
 * One page of a paginated list.
 *
 * @param <T> type of the items
 * @param data items of this page
 * @param currentPage number of this page (from 1)
 * @param lastPage number of the last page
 * @param perPage page size
 * @param total number of items over every page
 */
public record Page<T>(List<T> data, int currentPage, int lastPage, int perPage, long total) {

  /**
   * Creates a page.
   *
   * @param data items of this page
   * @param currentPage number of this page (from 1)
   * @param lastPage number of the last page
   * @param perPage page size
   * @param total number of items over every page
   */
  public Page {
    data = List.copyOf(data);
  }

  /**
   * Whether a next page exists.
   *
   * @return true when {@code currentPage < lastPage}
   */
  public boolean hasMore() {
    return currentPage < lastPage;
  }

  static <T> Page<T> fromJson(Object body, Function<Object, T> map) {
    Map<String, Object> object = Data.object(body, "body");
    List<T> items = new ArrayList<>();
    for (Object item : Data.array(object.get("data"), "data")) {
      items.add(map.apply(item));
    }
    Map<String, Object> meta = Data.optionalObject(object, "meta");
    Integer current = Data.nullableInt(meta, "current_page");
    Integer last = Data.nullableInt(meta, "last_page");
    Integer perPage = Data.nullableInt(meta, "per_page");
    Long total = Data.nullableLong(meta, "total");
    return new Page<>(
        items,
        current == null ? 1 : current,
        last == null ? 1 : last,
        perPage == null ? items.size() : perPage,
        total == null ? items.size() : total);
  }
}
