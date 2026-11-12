"""Point de réception des notifications (callback_url) avec Flask.

    pip install flask && LABPAY_WEBHOOK_SECRET=whsec_… flask --app examples/webhook_flask run
"""

import os

from flask import Flask, abort, request  # type: ignore[import-not-found, unused-ignore]

from labpay import InvalidWebhookPayloadError, SignatureVerificationError, webhooks

app = Flask(__name__)
SECRET = os.environ.get("LABPAY_WEBHOOK_SECRET", "")


@app.post("/webhooks/labpay")  # type: ignore[misc, untyped-decorator, unused-ignore]
def labpay_webhook() -> tuple[str, int]:
    try:
        event = webhooks.construct_event(request.get_data(), request.headers.get(webhooks.SIGNATURE_HEADER), SECRET)
    except (SignatureVerificationError, InvalidWebhookPayloadError):
        abort(400)
    delivery = request.headers.get(webhooks.DELIVERY_HEADER, event.payment.id)  # dédoublonner
    if event.is_payment_completed:
        pass  # Livrer la commande event.payment.reference, une seule fois par `delivery`.
    print(delivery, event.name, event.payment.id)
    return "", 200  # répondre en moins de 10 s
