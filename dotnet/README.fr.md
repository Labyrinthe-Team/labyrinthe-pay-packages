# SDK .NET LabPay

SDK officiel de [LabPay](https://pay.labyrinthe-rdc.com) : encaisser et envoyer du Mobile Money (M-Pesa, Airtel
Money, Orange Money) en RD Congo, en CDF et en USD. Couvre l'**API V2** (la V1 n'est pas couverte).

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0), non compilé dans ce premier jet.** Le code, les tests et les exemples ont été écrits sans
> SDK .NET disponible et n'ont **encore jamais été compilés ni exécutés**. Lancez `dotnet build` et `dotnet test` avant
> tout usage. Pas encore publié sur NuGet.

- `Labyrinthe.LabPay` : `netstandard2.0` et `net8.0`, `HttpClient`, `System.Text.Json` sans réflexion, API asynchrone
  avec `CancellationToken`.
- `Labyrinthe.LabPay.AspNetCore` (`net8.0`) : `services.AddLabPay(configuration)` et `app.MapLabPayWebhooks()`.

## Démarrage (mode test)

```csharp
using var labpay = new LabPayClient(new LabPayClientOptions { ApiKey = "test_…" }); // défaut : LABPAY_API_KEY

var paiement = await labpay.Deposits.CreateAsync(new MobileMoneyRequest
{
    Amount = 1000m,                 // montant net crédité (decimal : exact) ; le client paie ce montant + les frais
    Currency = "CDF",
    Phone = "0813456789",           // numéro de la sandbox : réussite
    Reference = "ORDER-42",         // unique par token : un renvoi rend le même paiement
    CallbackUrl = "https://boutique.example/webhooks/labpay",
});
```

## À retenir

- Ressources : `Deposits`, `Payouts` (`CreateAsync`, `CancelAsync`), `Refunds`, `Payments` (`GetAsync`, `SyncAsync`,
  `ListAsync`, `IterateAsync`, `FindByReferenceAsync`), `Providers` (`CountriesAsync`, `ListAsync`,
  `AvailabilityAsync`, `PredictAsync`), `Account` (`GetAsync`, `BalancesAsync`, `BalanceAsync`,
  `RotateWebhookSecretAsync`, `RotateTestKeyAsync`), `Notifications` (`ListAsync`, `ResendAsync`), `Webhook`.
  Tableau détaillé dans le [README anglais](README.md#api-coverage).
- Montants : `decimal` en entrée, chaînes exactes en sortie (`AmountValue` pour un `decimal`), jamais de `double`.
- **Ne livrez que sur `Completed`.** Un paiement refusé à l'initiation (422 avec le paiement) est **renvoyé** avec le
  statut `Failed`, pas levé.
- Sans `Reference`, le SDK génère `sdk_<uuid>` (`AutoReference = false` pour désactiver) : relance sans risque de
  double paiement. Rotations, renvois, annulations et paiements sans référence ne sont jamais relancés après une
  erreur réseau ou un 5xx.
- Exceptions typées (`LabPayException` → `ApiException` → `InsufficientBalanceException`…) : testez `Code` ou le type,
  jamais le message (en français).
- Notifications : `app.MapLabPayWebhooks()` (ASP.NET Core) ou `Webhook.ConstructEvent(corpsBrut, entête, secret)`,
  vérification en temps constant sur le corps brut, tolérance de 300 s, plusieurs secrets acceptés.

## Développement

```bash
dotnet build -c Release && dotnet test
```

Numéros de test : voir le [README PHP](../php/README.fr.md#numéros-de-test).

## Licence

MIT — Labyrinthe Innovations SARL.
