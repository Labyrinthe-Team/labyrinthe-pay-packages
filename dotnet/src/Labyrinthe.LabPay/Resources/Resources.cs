using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.LabPay.Internal;

namespace Labyrinthe.LabPay.Resources;

/// <summary>Base of the resources of <see cref="LabPayClient"/> (not meant to be derived outside the SDK).</summary>
public abstract class ResourceBase
{
    private protected ResourceBase(Transport transport, bool autoReference)
    {
        Transport = transport;
        AutoReference = autoReference;
    }

    private protected Transport Transport { get; }

    private protected bool AutoReference { get; }

    /// <summary>La référence donnée (validée), sinon une référence générée <c>sdk_&lt;uuid v4&gt;</c>, sinon null.</summary>
    private protected string? ReferenceFor(string? value) =>
        Input.ReferenceOrNull(value)
        ?? (AutoReference ? LabPayClient.AutoReferencePrefix + Guid.NewGuid().ToString("D", CultureInfo.InvariantCulture) : null);

    /// <summary>Dépôt ou envoi : même corps, même politique de relance.</summary>
    private protected async Task<Payment> InitiateAsync(string path, MobileMoneyRequest request, CancellationToken cancellationToken)
    {
        Guard.NotNull(request, nameof(request));
        var fields = new List<KeyValuePair<string, object?>>
        {
            new("amount", Input.Amount(request.Amount)),
            new("currency", Input.Currency(request.Currency)),
            new("phone", Input.Phone(request.Phone)),
            new("country", Input.Country(request.Country)),
            new("provider", Input.Provider(request.Provider)),
            new("callback_url", Input.CallbackUrl(request.CallbackUrl)),
            new("customer_message", Input.CustomerMessageOrNull(request.CustomerMessage)),
            new("metadata", Input.Metadata(request.Metadata)),
        };
        var reference = ReferenceFor(request.Reference);
        fields.Add(new("reference", reference));

        // Avec une référence, un renvoi rend le paiement existant : la relance est sûre.
        var response = await Transport.SendAsync(
            HttpMethod.Post,
            path,
            reference is null ? RetryMode.ThrottledOnly : RetryMode.Safe,
            null,
            Json.Write(fields),
            paymentOnRefusal: true,
            cancellationToken).ConfigureAwait(false);
        return Mapping.ToPayment(response.Data);
    }

    private protected static string Segment(string id) => Uri.EscapeDataString(id);
}

/// <summary>Collect from a customer (<c>client.Deposits</c>, <c>POST /V2/deposits</c>).</summary>
public sealed class DepositsResource : ResourceBase
{
    internal DepositsResource(Transport transport, bool autoReference)
        : base(transport, autoReference)
    {
    }

    /// <summary>Asks the customer to pay: they get a PIN prompt on their phone. The returned payment is usually
    /// <c>pending</c>; its final status arrives by notification on <c>CallbackUrl</c> (or read it with
    /// <see cref="PaymentsResource.GetAsync"/>). Only deliver an order on <c>completed</c>.</summary>
    /// <remarks>A payment refused at initiation is returned with the <c>failed</c> status (and <c>Failure</c>), not
    /// thrown. A <see cref="ConnectionException"/> does not mean the payment failed: resend with the same
    /// <c>Reference</c>.</remarks>
    /// <param name="request">Amount, currency, phone…</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The payment.</returns>
    public Task<Payment> CreateAsync(MobileMoneyRequest request, CancellationToken cancellationToken = default) =>
        InitiateAsync("/deposits", request, cancellationToken);
}

/// <summary>Send money to a Mobile Money wallet (<c>client.Payouts</c>, <c>POST /V2/payouts</c>).</summary>
public sealed class PayoutsResource : ResourceBase
{
    internal PayoutsResource(Transport transport, bool autoReference)
        : base(transport, autoReference)
    {
    }

    /// <summary>Sends <c>Amount</c> to the number. Your balance is debited immediately with amount + payout fees, and
    /// credited back in full if the payout fails.</summary>
    /// <param name="request">Amount, currency, phone…</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The payout, usually <c>pending</c>.</returns>
    public Task<Payment> CreateAsync(MobileMoneyRequest request, CancellationToken cancellationToken = default) =>
        InitiateAsync("/payouts", request, cancellationToken);

    /// <summary>Cancels a payout still queued by the provider (<c>GatewayStatus: ENQUEUED</c>, see
    /// <see cref="Payment.IsQueued"/>). Asynchronous: the payout then moves to <c>failed</c> and your balance is
    /// credited back. Not retried after a network error or a 5xx.</summary>
    /// <param name="id">Payout id (<c>PAY-…</c>).</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The payout.</returns>
    public async Task<Payment> CancelAsync(string id, CancellationToken cancellationToken = default)
    {
        var path = "/payouts/" + Segment(Input.Id(id)) + "/cancel";
        var response = await Transport.SendAsync(HttpMethod.Post, path, RetryMode.ThrottledOnly, null, null, false, cancellationToken).ConfigureAwait(false);
        return Mapping.ToPayment(response.Data);
    }
}

/// <summary>Refund a deposit (<c>client.Refunds</c>, <c>POST /V2/refunds</c>).</summary>
public sealed class RefundsResource : ResourceBase
{
    internal RefundsResource(Transport transport, bool autoReference)
        : base(transport, autoReference)
    {
    }

    /// <summary>Refunds all or part of a completed V2 deposit, to the wallet that paid. Fees are not refunded; one
    /// refund in progress at a time per deposit.</summary>
    /// <param name="request">Deposit id, optional amount…</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The refund (<c>RFD-…</c>), usually <c>pending</c>.</returns>
    public async Task<Payment> CreateAsync(RefundRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));
        var fields = new List<KeyValuePair<string, object?>>
        {
            new("payment_id", Input.Id(request.PaymentId, "paymentId")),
            new("amount", request.Amount is { } amount ? Input.Amount(amount) : null),
            new("callback_url", Input.CallbackUrl(request.CallbackUrl)),
            new("metadata", Input.Metadata(request.Metadata)),
        };
        var reference = ReferenceFor(request.Reference);
        fields.Add(new("reference", reference));
        var response = await Transport.SendAsync(
            HttpMethod.Post,
            "/refunds",
            reference is null ? RetryMode.ThrottledOnly : RetryMode.Safe,
            null,
            Json.Write(fields),
            paymentOnRefusal: true,
            cancellationToken).ConfigureAwait(false);
        return Mapping.ToPayment(response.Data);
    }
}

/// <summary>Track payments (<c>client.Payments</c>).</summary>
public sealed class PaymentsResource : ResourceBase
{
    internal PaymentsResource(Transport transport, bool autoReference)
        : base(transport, autoReference)
    {
    }

    /// <summary>One payment by its LabPay id, in the mode of the key.</summary>
    /// <param name="id"><c>DEP-…</c>, <c>PAY-…</c> or <c>RFD-…</c>.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The payment.</returns>
    /// <exception cref="NotFoundException">Unknown for this token and mode.</exception>
    public async Task<Payment> GetAsync(string id, CancellationToken cancellationToken = default)
    {
        var response = await Transport.SendAsync(HttpMethod.Get, "/payments/" + Segment(Input.Id(id)), RetryMode.Safe, null, null, false, cancellationToken).ConfigureAwait(false);
        return Mapping.ToPayment(response.Data);
    }

    /// <summary>Re-reads the status of a pending payment now and returns it updated (an already final payment is
    /// returned as is). A safety net: prefer notifications, never poll in a tight loop.</summary>
    /// <param name="id">Payment id.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>The payment.</returns>
    public async Task<Payment> SyncAsync(string id, CancellationToken cancellationToken = default)
    {
        var path = "/payments/" + Segment(Input.Id(id)) + "/sync";
        var response = await Transport.SendAsync(HttpMethod.Post, path, RetryMode.Safe, null, null, false, cancellationToken).ConfigureAwait(false);
        return Mapping.ToPayment(response.Data);
    }

    /// <summary>V2 payments of the token, newest first, in the mode of the key.</summary>
    /// <param name="request">Filters and page; <see langword="null"/> for the first page of every payment.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    /// <returns>One page.</returns>
    public async Task<Page<Payment>> ListAsync(ListPaymentsRequest? request = null, CancellationToken cancellationToken = default)
    {
        var filters = request ?? new ListPaymentsRequest();
        if (filters.From is { } from && filters.To is { } to && to.Date < from.Date)
        {
            throw new InvalidArgumentException("To must be on or after From.");
        }

        var query = new List<KeyValuePair<string, string>>();
        void Add(string name, string? value)
        {
            if (value is not null && value.Length > 0)
            {
                query.Add(new KeyValuePair<string, string>(name, value));
            }
        }

        Add("status", filters.Status is { } status ? ApiValues.Filter(status.ToApiString(), "Status") : null);
        Add("operation", filters.Operation is { } operation ? ApiValues.Filter(operation.ToApiString(), "Operation") : null);
        Add("currency", filters.Currency is null ? null : Input.Currency(filters.Currency));
        Add("country", Input.Country(filters.Country));
        Add("reference", filters.Reference);
        Add("from", filters.From?.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture));
        Add("to", filters.To?.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture));
        Add("per_page", Input.PerPage(filters.PerPage)?.ToString(CultureInfo.InvariantCulture));
        Add("page", Input.Page(filters.Page)?.ToString(CultureInfo.InvariantCulture));

        var response = await Transport.SendAsync(HttpMethod.Get, "/payments", RetryMode.Safe, query, null, false, cancellationToken).ConfigureAwait(false);
        return Mapping.ToPage(response.Body, item => Mapping.ToPayment(item));
    }

    /// <summary>Every payment matching the filters, fetching the next pages (100 per request by default) as you
    /// iterate.</summary>
    /// <param name="request">Filters (<c>Page</c> is ignored).</param>
    /// <param name="cancellationToken">Cancels the requests.</param>
    /// <returns>The payments, lazily.</returns>
    /// <example><code>await foreach (var payment in labpay.Payments.IterateAsync(new ListPaymentsRequest { Status = PaymentStatus.Completed })) { … }</code></example>
    public async IAsyncEnumerable<Payment> IterateAsync(ListPaymentsRequest? request = null, [EnumeratorCancellation] CancellationToken cancellationToken = default)
    {
        var filters = request ?? new ListPaymentsRequest();
        int? page = 1;
        while (page is { } current)
        {
            var result = await ListAsync(
                new ListPaymentsRequest
                {
                    Status = filters.Status,
                    Operation = filters.Operation,
                    Currency = filters.Currency,
                    Country = filters.Country,
                    Reference = filters.Reference,
