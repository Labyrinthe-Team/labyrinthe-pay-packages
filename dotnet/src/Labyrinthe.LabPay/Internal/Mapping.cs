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
        var limits = new Dictionary<string, AmountLimits>(StringComparer.Ordinal);
        foreach (var limit in reader.OptionalObject(data, "limits").EnumerateObject())
        {
            limits[limit.Name] = ToLimits(limit.Value);
        }

        var currencies = new List<string>();
        if (Json.Field(data, "currencies") is { ValueKind: JsonValueKind.Array } list)
        {
            foreach (var item in list.EnumerateArray())
            {
                if (item.ValueKind == JsonValueKind.String && item.GetString() is { } currency)
                {
                    currencies.Add(currency);
                }
            }
        }

        return new Country
        {
            Code = reader.String(data, "country"),
            Name = reader.NullableString(data, "name"),
            DialCode = reader.NullableString(data, "dial_code"),
            Currencies = currencies,
            Limits = limits,
            IsDefault = Json.Bool(data, "default", false),
        };
    }

    public static Provider ToProvider(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "provider");
        var currencies = new Dictionary<string, ProviderCurrency>(StringComparer.Ordinal);
        foreach (var entry in reader.OptionalObject(data, "currencies").EnumerateObject())
        {
            var rules = Json.Lenient(entry.Value);
            currencies[entry.Name] = new ProviderCurrency
            {
                MinAmount = reader.NullableString(rules, "min_amount"),
                MaxAmount = reader.NullableString(rules, "max_amount"),
                AmountDecimals = reader.NullableString(rules, "decimals"),
                Status = reader.NullableString(rules, "status"),
                PinPrompt = Json.Raw(rules, "pin_prompt"),
                PinPromptRevivable = Json.Raw(rules, "pin_prompt_revivable"),
                PinPromptInstructions = Json.Raw(rules, "pin_prompt_instructions"),
            };
        }

        return new Provider
        {
            Code = reader.String(data, "provider"),
            Country = reader.NullableString(data, "country"),
            Name = reader.NullableString(data, "name"),
            NameDisplayedToCustomer = reader.NullableString(data, "name_displayed_to_customer"),
            Logo = reader.NullableString(data, "logo"),
            Currencies = currencies,
        };
    }

    public static ProviderAvailability ToAvailability(string provider, JsonElement value)
    {
        var data = Json.Lenient(value);
        var statuses = new Dictionary<string, string?>(StringComparer.Ordinal);
        foreach (var entry in data.EnumerateObject())
        {
            if (entry.Name != "country")
            {
                statuses[entry.Name] = entry.Value.ValueKind == JsonValueKind.String ? entry.Value.GetString() : null;
            }
        }

        return new ProviderAvailability
        {
            Provider = provider,
            Country = Json.Api.NullableString(data, "country"),
            Statuses = statuses,
        };
    }

    public static ProviderPrediction ToPrediction(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "data");
        return new ProviderPrediction
        {
            Phone = reader.String(data, "phone"),
            Provider = reader.String(data, "provider"),
            Country = reader.String(data, "country"),
        };
    }

    public static Notification ToNotification(JsonElement value)
    {
        var reader = Json.Api;
        var data = reader.Object(value, "notification");
        var id = Json.NullableLong(data, "id") ?? throw reader.Invalid("id", "an integer");
        return new Notification
        {
            Id = id,
            EventName = reader.NullableString(data, "event") ?? "unknown",
            PaymentId = reader.NullableString(data, "payment_id"),
            Url = reader.NullableString(data, "url"),
            Status = ApiValues.ParseNotificationStatus(reader.NullableString(data, "status")),
            Attempts = Json.NullableInt(data, "attempts") ?? 0,
            LastStatusCode = Json.NullableInt(data, "last_status_code"),
            LastError = reader.NullableString(data, "last_error"),
            NextAttemptAt = Json.NullableDate(data, "next_attempt_at"),
            DeliveredAt = Json.NullableDate(data, "delivered_at"),
            CreatedAt = Json.NullableDate(data, "created_at"),
        };
    }

    private static AmountLimits ToLimits(JsonElement value)
    {
        var data = Json.Lenient(value);
        return new AmountLimits
        {
            MinAmount = Json.Api.NullableString(data, "min_amount"),
            MaxAmount = Json.Api.NullableString(data, "max_amount"),
        };
    }
}
