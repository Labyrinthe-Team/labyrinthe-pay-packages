package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.exception.InvalidArgumentException;
import cd.labyrinthe.labpay.internal.Input;
import cd.labyrinthe.labpay.internal.RetryMode;
import cd.labyrinthe.labpay.internal.Transport;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.NoSuchElementException;
import java.util.Optional;
import java.util.Spliterator;
import java.util.Spliterators;
import java.util.stream.Stream;
import java.util.stream.StreamSupport;

/** Track payments ({@code client.payments()}). */
public final class PaymentService extends Resource {

  private static final List<String> STATUSES = List.of("pending", "completed", "failed");
  private static final List<String> OPERATIONS = List.of("deposit", "payout", "refund");

  PaymentService(Transport transport, boolean autoReference) {
    super(transport, autoReference);
  }

  /**
   * One payment by its LabPay id, in the mode of the key.
   *
   * @param id {@code DEP-…}, {@code PAY-…} or {@code RFD-…}
   * @return the payment
   * @throws cd.labyrinthe.labpay.exception.NotFoundException when unknown for this token and mode
   */
  public Payment get(String id) {
    String path = "/payments/" + Input.pathSegment(Input.id(id, "id"));
    return Payment.fromJson(
        transport.request("GET", path, RetryMode.SAFE, null, null, false).data());
  }

  /**
   * Re-reads the status of a pending payment now and returns it updated (an already final payment
   * is returned as is). A safety net: prefer notifications, never poll in a tight loop.
   *
   * @param id payment id
   * @return the payment
   */
  public Payment sync(String id) {
    String path = "/payments/" + Input.pathSegment(Input.id(id, "id")) + "/sync";
    return Payment.fromJson(
        transport.request("POST", path, RetryMode.SAFE, null, null, false).data());
  }

  /**
   * V2 payments of the token, newest first, in the mode of the key.
   *
   * @param params filters and page
   * @return one page
   */
  public Page<Payment> list(ListPaymentsParams params) {
    ListPaymentsParams p = params == null ? ListPaymentsParams.none() : params;
    if (p.from() != null && p.to() != null && p.to().isBefore(p.from())) {
      throw new InvalidArgumentException("to must be on or after from.");
    }
    Map<String, Object> query = new LinkedHashMap<>();
    query.put(
        "status", p.status() == null ? null : Input.oneOf(p.status().value(), STATUSES, "status"));
    query.put(
        "operation",
        p.operation() == null ? null : Input.oneOf(p.operation().value(), OPERATIONS, "operation"));
    query.put("currency", p.currency() == null ? null : Input.currency(p.currency()));
    query.put("country", Input.country(p.country()));
    query.put("reference", p.reference());
    query.put("from", Input.date(p.from(), "from"));
    query.put("to", Input.date(p.to(), "to"));
    query.put("per_page", Input.perPage(p.perPage()));
    query.put("page", Input.page(p.page()));
    Transport.Response response =
        transport.request("GET", "/payments", RetryMode.SAFE, query, null, false);
    return Page.fromJson(response.body(), Payment::fromJson);
  }

  /**
   * The first page of every payment.
   *
   * @return one page
   */
  public Page<Payment> list() {
    return list(ListPaymentsParams.none());
  }

  /**
   * Every payment matching the filters, fetching the next pages (100 per request by default) as you
   * iterate. Each iteration starts again from the first page.
   *
   * @param params filters ({@code page} is ignored)
   * @return a lazy iterable
   */
  public Iterable<Payment> iterate(ListPaymentsParams params) {
    ListPaymentsParams p = params == null ? ListPaymentsParams.none() : params;
    ListPaymentsParams.Builder base = p.toBuilder().page(null);
    if (p.perPage() == null) {
      base.perPage(Input.MAX_PER_PAGE);
    }
    ListPaymentsParams filters = base.build();
    return () -> new PageIterator(filters);
  }

  /**
   * Every payment matching the filters, as a lazy stream.
   *
   * @param params filters ({@code page} is ignored)
   * @return a sequential stream
   */
  public Stream<Payment> stream(ListPaymentsParams params) {
    return StreamSupport.stream(
        Spliterators.spliteratorUnknownSize(iterate(params).iterator(), Spliterator.ORDERED),
        false);
  }

  /**
   * The payment carrying your reference (references are unique per token and mode).
   *
   * @param reference your reference
   * @return the payment, or empty
   */
  public Optional<Payment> findByReference(String reference) {
    if (reference == null) {
      throw new InvalidArgumentException("reference is required.");
    }
    Page<Payment> page =
        list(ListPaymentsParams.builder().reference(Input.reference(reference)).perPage(1).build());
    return page.data().stream().findFirst();
  }

  /** Itérateur paresseux sur toutes les pages. */
  private final class PageIterator implements Iterator<Payment> {
    private final ListPaymentsParams filters;
    private Iterator<Payment> current = java.util.Collections.emptyIterator();
    private Integer nextPage = 1;

    PageIterator(ListPaymentsParams filters) {
      this.filters = filters;
    }

    @Override
    public boolean hasNext() {
      while (!current.hasNext() && nextPage != null) {
        Page<Payment> page = list(filters.toBuilder().page(nextPage).build());
        current = page.data().iterator();
        nextPage = page.hasMore() ? page.currentPage() + 1 : null;
      }
      return current.hasNext();
    }

    @Override
    public Payment next() {
      if (!hasNext()) {
        throw new NoSuchElementException();
      }
      return current.next();
    }
  }
}
