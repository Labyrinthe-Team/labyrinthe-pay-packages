package cd.labyrinthe.labpay;

/**
 * Why a payment failed, as passed on by LabPay.
 *
 * @param code stable code, e.g. {@code PAYER_LIMIT_REACHED}
 * @param message human-readable message, or null
 */
public record PaymentFailure(String code, String message) {}
