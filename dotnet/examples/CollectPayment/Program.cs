// Collects 1 000 CDF from a customer with a test key (sandbox, no real money).
//
//   LABPAY_API_KEY=test_… dotnet run --project examples/CollectPayment
using System;
using System.Collections.Generic;
using Labyrinthe.LabPay;

using var labpay = new LabPayClient(); // reads LABPAY_API_KEY

try
{
    var payment = await labpay.Deposits.CreateAsync(new MobileMoneyRequest
    {
        Amount = 1000m,                 // net amount credited to you; the customer pays it + fees
        Currency = "CDF",
        Phone = "0813456789",           // sandbox number: success
        Reference = "ORDER-42",         // unique per token: a resend returns the same payment
        CallbackUrl = "https://shop.example/webhooks/labpay",
        Metadata = new Dictionary<string, object?> { ["orderId"] = 42 },
    });

    if (payment.IsFailed)
    {
        // Refused at initiation (e.g. payer limit): nothing to collect.
        Console.WriteLine($"Refused: {payment.Failure?.Code}");
        return;
    }

    // "pending": the customer confirms with their PIN; the final status arrives on CallbackUrl.
    Console.WriteLine($"{payment.Id} {payment.Status.ToApiString()}");
}
catch (InvalidRequestException e)
{
    Console.WriteLine($"Invalid request ({e.Code}): {e.Message}");
}
catch (ConnectionException)
{
    // The payment may exist: resend the same reference, or look it up.
    var existing = await labpay.Payments.FindByReferenceAsync("ORDER-42");
    Console.WriteLine(existing is null ? "Not created." : existing.Status.ToApiString());
}
