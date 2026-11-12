package cd.labyrinthe.labpay;

import cd.labyrinthe.labpay.internal.Data;
import java.util.Map;

/**
 * Rules of a provider for one currency.
 *
 * @param minAmount smallest amount, or null
 * @param maxAmount largest amount, or null
 * @param decimals decimals accepted in the amount (e.g. {@code NONE}: whole amount only), or null
 * @param status availability, e.g. {@code OPERATIONAL}, or null
 * @param pinPrompt how the PIN prompt is shown (raw JSON value), or null
 * @param pinPromptRevivable whether the PIN prompt can be shown again (raw JSON value), or null
 * @param pinPromptInstructions instructions for the customer (raw JSON value), or null
 */
public record ProviderCurrency(
    String minAmount,
    String maxAmount,
    String decimals,
    String status,
    Object pinPrompt,
    Object pinPromptRevivable,
    Object pinPromptInstructions) {

  static ProviderCurrency fromJson(Object value) {
    Map<String, Object> data = Data.lenientObject(value);
    return new ProviderCurrency(
        Data.nullableString(data, "min_amount"),
        Data.nullableString(data, "max_amount"),
        Data.nullableString(data, "decimals"),
        Data.nullableString(data, "status"),
        data.get("pin_prompt"),
        data.get("pin_prompt_revivable"),
        data.get("pin_prompt_instructions"));
  }
}
