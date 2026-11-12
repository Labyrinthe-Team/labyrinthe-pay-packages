using System;
using System.Collections.Generic;
using System.Text.Json;

namespace Labyrinthe.LabPay.Internal;

/// <summary>Conversion du JSON de l'API en modèles. Mêmes règles que <c>node/src/types.ts</c>. Usage interne.</summary>
internal static class Mapping
{
    public static Payment ToPayment(JsonElement value, Json reader)
    {
        var data = reader.Object(value, "payment");
        PaymentFailure? failure = null;
        if (Json.Raw(data, "failure") is { } rawFailure)
        {
            var f = reader.Object(rawFailure, "failure");
            failure = new PaymentFailure { Code = reader.String(f, "code"), Message = reader.NullableString(f, "message") };
        }

        return new Payment
        {
            Id = reader.String(data, "id"),
            Reference = reader.NullableString(data, "reference"),
            Operation = ApiValues.ParseOperation(reader.NullableString(data, "operation")),
            Livemode = Json.Bool(data, "livemode", true),
            Status = ApiValues.ParsePaymentStatus(reader.NullableString(data, "status")),
            GatewayStatus = reader.NullableString(data, "gateway_status"),
            Amount = reader.String(data, "amount"),
            Fee = reader.NullableString(data, "fee"),
            TotalAmount = reader.NullableString(data, "total_amount"),
            Currency = reader.String(data, "currency"),
            Country = reader.NullableString(data, "country"),
            Phone = reader.NullableString(data, "phone"),
            Provider = reader.NullableString(data, "provider"),
            ProviderTransactionId = reader.NullableString(data, "provider_transaction_id"),
            Failure = failure,
            Metadata = reader.NullableStringMap(data, "metadata"),
            CreatedAt = Json.NullableDate(data, "created_at"),
            CompletedAt = Json.NullableDate(data, "completed_at"),
            RefundedAmount = reader.NullableString(data, "refunded_amount"),
            RefundedPaymentId = reader.NullableString(data, "refunded_payment_id"),
            Raw = data.Clone(),
        };
    }

    public static Payment ToPayment(JsonElement value) => ToPayment(value, Json.Api);

    public static Page<T> ToPage<T>(JsonElement body, Func<JsonElement, T> map)
    {
        var reader = Json.Api;
        var root = reader.Object(body, "body");
        var items = new List<T>();
        foreach (var item in reader.Array(Json.Field(root, "data"), "data"))
        {
            items.Add(map(item));
        }

        var meta = reader.OptionalObject(root, "meta");
        return new Page<T>(
            items,
            Json.NullableInt(meta, "current_page") ?? 1,
            Json.NullableInt(meta, "last_page") ?? 1,
            Json.NullableInt(meta, "per_page") ?? items.Count,
            Json.NullableLong(meta, "total") ?? items.Count);
    }

    public static List<T> ToList<T>(JsonElement data, Func<JsonElement, T> map)
    {
        var items = new List<T>();
        foreach (var item in Json.Api.Array(data, "data"))
        {
            items.Add(map(item));
        }

        return items;
    }

    public static Balance ToBalance(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "balance");
        return new Balance
        {
            Currency = reader.String(data, "currency"),
            Amount = reader.String(data, "balance"),
            UpdatedAt = Json.NullableDate(data, "updated_at"),
        };
    }

    public static Account ToAccount(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "account");
        var fees = reader.OptionalObject(data, "fees");
        return new Account
        {
            Mode = ApiValues.ParseMode(reader.NullableString(data, "mode")),
            Merchant = reader.NullableString(data, "merchant"),
            LiveEnabled = Json.Bool(data, "live_enabled", false),
            KycVerified = Json.Bool(data, "kyc_verified", false),
            DepositFeeRate = reader.NullableString(fees, "deposit_rate"),
            PayoutFeeRate = reader.NullableString(fees, "payout_rate"),
            TestKey = reader.NullableString(data, "test_key"),
            WebhookSecret = reader.NullableString(data, "webhook_secret"),
            WebhookSecretPreviousExpiresAt = Json.NullableDate(data, "webhook_secret_previous_expires_at"),
        };
    }

    public static WebhookSecret ToWebhookSecret(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "data");
        return new WebhookSecret
        {
            Secret = reader.String(data, "webhook_secret"),
            PreviousExpiresAt = Json.NullableDate(data, "webhook_secret_previous_expires_at"),
        };
    }

    public static Country ToCountry(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "country");
