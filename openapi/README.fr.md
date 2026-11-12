# API V2 de LabPay — spécification OpenAPI

[`openapi.yaml`](openapi.yaml) (OpenAPI 3.1) décrit l'API marchande publique V2 de LabPay
(`https://api.labyrinthe-rdc.com/api/V2`) : dépôts, envois, remboursements, suivi, pays et opérateurs, compte et
soldes, notifications, et la notification envoyée sur la `callback_url` du marchand.

*English version: [README.md](README.md).*

C'est la base commune des SDK de ce dépôt. Elle a été **dérivée du code du moteur de paiement**
(`labyrinthe-pay-back` : routes V2, contrôleurs, form requests, `PaymentPresenter`, `MerchantNotifier`, codes de
`PaymentRejectedException`) et recoupée avec la documentation (`labyrinthe-pay-docs`). La V1 et les routes
d'administration sont hors périmètre.

Le test de contrat du SDK Node (`node/tests/contract.test.ts`) vérifie que les opérations de la spécification, celles
du SDK et les routes marchandes du moteur coïncident, que chaque `$ref` se résout et que chaque code d'erreur a une
erreur typée.
