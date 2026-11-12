// Sends 5 000 CDF to a wallet after checking the number and the balance.
//
//   LABPAY_API_KEY=test_… dotnet run --project examples/SendPayout
using System;
using System.Globalization;
using Labyrinthe.LabPay;

using var labpay = new LabPayClient(new LabPayClientOptions
{
    // ApiKey = "test_…",           // default: LABPAY_API_KEY
    Logger = LabPayLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}"), LabPayLogLevel.Debug),
});

try
{
    var prediction = await labpay.Providers.PredictAsync("0813456789");
    Console.WriteLine($"Provider: {prediction.Provider}");

    var balance = await labpay.Account.BalanceAsync("CDF");
    Console.WriteLine($"CDF balance: {(balance?.Value ?? 0m).ToString(CultureInfo.InvariantCulture)}");

    var payout = await labpay.Payouts.CreateAsync(new MobileMoneyRequest
    {
        Amount = 5000m,
        Currency = "CDF",
        Phone = "0813456789",
        Reference = "PAYOUT-2026-11-0042",
    });
    Console.WriteLine($"{payout.Id} {payout.Status.ToApiString()}, debited {payout.TotalAmount}");

    if (payout.IsQueued)
    {
        // Provider delayed: the payout can still be cancelled.
        await labpay.Payouts.CancelAsync(payout.Id);
    }
}
catch (InvalidPhoneNumberException e)
{
    Console.WriteLine($"Invalid number: {e.Message}");
}
catch (InsufficientBalanceException)
{
    Console.WriteLine("Insufficient balance: nothing was sent.");
}
