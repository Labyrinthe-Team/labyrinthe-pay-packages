"""Encaisser 1 000 CDF avec la clé de test, puis suivre le paiement.

    LABPAY_API_KEY=test_… python examples/collect_payment.py
"""

import time

from labpay import APIConnectionError, ApiError, LabPay

with LabPay() as labpay:  # LABPAY_API_KEY
    try:
        payment = labpay.deposits.create(
            amount="1000",
            currency="CDF",
            phone="0813456789",  # numéro de la sandbox : réussite
            reference=f"ORDER-{int(time.time())}",  # unique par token : un renvoi rend le même paiement
            customer_message="Commande 42",
            metadata={"orderId": "42"},
        )
    except APIConnectionError:
        raise SystemExit("Network error: the payment may exist, resend with the same reference.") from None
    except ApiError as error:
        raise SystemExit(f"Refused: {error.code} ({error.message})") from None

    print(f"{payment.id}: {payment.status}, the customer pays {payment.total_amount} {payment.currency}")

    # Filet de sécurité seulement : en production, attendez la notification sur callback_url.
    for _ in range(10):
        if payment.is_final:
            break
        time.sleep(5)
        payment = labpay.payments.sync(payment.id)
    print("Final status:", payment.status, payment.failure or "")
