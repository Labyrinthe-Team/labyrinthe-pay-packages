# SDK Python LabPay

SDK Python officiel de [LabPay](https://pay.labyrinthe-rdc.com) : encaisser et envoyer du Mobile Money (M-Pesa,
Airtel Money, Orange Money) en RD Congo, en CDF et en USD. Couvre l'**API V2** (la V1 n'est pas couverte).

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0)** — pas encore publié sur PyPI. Client synchrone seulement (client asyncio prévu).

## Démarrage (mode test)

```python
from labpay import LabPay

with LabPay(api_key="test_…") as labpay:        # clé de test : sandbox (par défaut : LABPAY_API_KEY)
    paiement = labpay.deposits.create(
        amount="1000", currency="CDF", phone="0813456789", reference="ORDER-42",
        callback_url="https://boutique.example/webhooks/labpay",
    )
```

## À retenir

- Ressources : `deposits`, `payouts` (`create`, `cancel`), `refunds`, `payments` (`get`, `sync`, `list`, `iterate`,
  `find_by_reference`), `providers` (`countries`, `list`, `availability`, `predict`), `account` (`get`, `balances`,
  `balance`, `rotate_webhook_secret`, `rotate_test_key`), `notifications` (`list`, `resend`), module `webhooks`.
- **Ne livrez qu'au statut `completed`.** Un paiement refusé dès l'initiation est renvoyé avec le statut `failed`.
- Montants en `str`, `int` ou `Decimal` (les `float` sont refusés).
- Sans `reference`, le SDK génère `sdk_<uuid>` pour relancer sans risque de double paiement ; une
  `APIConnectionError` sur un paiement ne veut pas dire « échec ».
- Notifications : `webhooks.construct_event(corps_brut, en_tete, secret)`, réponse 400 si la signature est invalide.

## Développement

```bash
python -m venv .venv && .venv/Scripts/pip install -r requirements-dev.txt
.venv/Scripts/python -m pytest && .venv/Scripts/python -m mypy
```
