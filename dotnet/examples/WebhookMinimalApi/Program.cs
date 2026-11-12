// Minimal API that collects payments and receives the signed LabPay notifications.
//
//   LabPay__ApiKey=test_… LabPay__WebhookSecret=whsec_… dotnet run --project examples/WebhookMinimalApi
//
// Then use https://<your host>/webhooks/labpay as callback_url.
using System;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.LabPay;
using Labyrinthe.LabPay.AspNetCore;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;

var builder = WebApplication.CreateBuilder(args);

// LabPayClient + options from the "LabPay" section (ApiKey, WebhookSecret…), IHttpClientFactory, ILogger.
builder.Services.AddLabPay(builder.Configuration);
builder.Services.AddLabPayWebhookHandler<PaymentHandler>();

var app = builder.Build();

// POST /webhooks/labpay: raw body, signature and timestamp checked, then PaymentHandler is called (400 otherwise).
app.MapLabPayWebhooks();

// Collects 1 000 CDF: POST /pay?phone=0813456789&order=42
app.MapPost("/pay", async (string phone, string order, LabPayClient labpay, CancellationToken cancellationToken) =>
{
    var payment = await labpay.Deposits.CreateAsync(
        new MobileMoneyRequest
        {
            Amount = 1000m,
            Currency = "CDF",
            Phone = phone,
            Reference = "ORDER-" + order,
            CallbackUrl = "https://shop.example/webhooks/labpay",
        },
        cancellationToken);
    return Results.Accepted(value: new { payment.Id, Status = payment.Status.ToApiString() });
});

app.Run();

/// <summary>Called for every verified notification. Answer fast; queue slow work. Deduplicate on Payment.Id.</summary>
internal sealed class PaymentHandler : ILabPayWebhookHandler
{
    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken)
    {
        if (webhookEvent.IsPaymentCompleted)
        {
            // Deliver the order once.
            Console.WriteLine($"Order paid: {webhookEvent.Payment.Reference}");
        }
        else if (webhookEvent.IsPaymentFailed)
        {
            Console.WriteLine($"Payment failed: {webhookEvent.Payment.Failure?.Code}");
        }

        return Task.CompletedTask;
    }
}
