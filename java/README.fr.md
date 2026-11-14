# SDK Java LabPay

SDK officiel de [LabPay](https://pay.labyrinthe-rdc.com) : encaisser et envoyer du Mobile Money (M-Pesa, Airtel
Money, Orange Money, MTN MoMo…) en Afrique, dans chaque pays ouvert sur la plateforme et dans sa devise (CDF, USD, XAF, XOF…). Couvre l'**API V2** (la V1 n'est pas couverte).

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0)** — pas encore publié sur Maven Central.

- `com.labyrinthe-rdc:labpay` : Java 17+, `java.net.http`, **aucune dépendance** (lecteur JSON interne : aucune
  version de Jackson imposée à votre application).
- `com.labyrinthe-rdc:labpay-spring-boot-starter` : auto-configuration Spring Boot 3 (propriétés `labpay.*`) et point
  d'entrée de notifications signées publiant des événements Spring.

## Installation

```xml
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labpay</artifactId>
  <version>0.1.0</version>
</dependency>
```

Spring Boot : `labpay-spring-boot-starter`. Avant la première publication : `./mvnw install` depuis ce dossier.

## Démarrage (mode test)

```java
LabPayClient labpay = LabPayClient.builder().apiKey("test_…").build(); // clé de test : sandbox (défaut : LABPAY_API_KEY)

Payment paiement = labpay.deposits().create(
    MobileMoneyParams.builder()
        .amount("1000")                // montant net crédité ; le client paie ce montant + les frais
        .currency("CDF")
        .phone("0813456789")           // numéro de la sandbox : réussite
        .reference("ORDER-42")         // unique par token : un renvoi rend le même paiement
        .callbackUrl("https://boutique.example/webhooks/labpay")
        .build());
System.out.println(paiement.id() + " " + paiement.status()); // DEP-01JCX… pending
```

## À retenir

- Services : `deposits()`, `payouts()` (`create`, `cancel`), `refunds()`, `payments()` (`get`, `sync`, `list`,
  `iterate`, `stream`, `findByReference`), `providers()` (`countries`, `list`, `availability`, `predict`), `account()`
  (`get`, `balances`, `balance`, `rotateWebhookSecret`, `rotateTestKey`), `notifications()` (`list`, `resend`),
  `Webhooks`. Tableau détaillé dans le [README anglais](README.md#api-coverage).
- Montants en **chaînes** (`"1500.00"`) ou `BigDecimal` exacts, jamais de `double`.
- **Ne livrez que sur `COMPLETED`.** Un paiement refusé à l'initiation (422 avec le paiement) est **renvoyé** avec le
  statut `FAILED`, pas levé.
- Sans `reference`, le SDK génère `sdk_<uuid>` (`autoReference(false)` pour désactiver) : un paiement est relancé
  après une erreur réseau ou un 5xx sans risque de double paiement. Rotations, renvois de notification, annulations et
  paiements sans référence ne sont jamais relancés après une erreur réseau ou un 5xx.
- Une `ConnectionException` sur un paiement ne veut **pas** dire qu'il a échoué : renvoyez la même référence ou
  appelez `payments().findByReference()`.
- Exceptions typées (`LabPayException` → `ApiException` → `InsufficientBalanceException`…) : testez `code()` ou la
  classe, jamais le message (en français).

## Notifications

```java
WebhookEvent event = Webhooks.constructEvent(corpsBrut, request.getHeader(Webhooks.SIGNATURE_HEADER), secret);
if (event.isPaymentCompleted()) {
  commandes.livrer(event.payment().reference()); // une seule fois : dédupliquez sur payment().id()
}
```

Vérification sur le **corps brut**, chaque `v1` en temps constant, tolérance de 300 s, plusieurs secrets acceptés.
Avec Spring Boot : `labpay.api-key` et `labpay.webhook.secret` suffisent ; le starter expose `POST /webhooks/labpay`
et publie un `WebhookEvent` (`@EventListener`).

## Développement

```bash
./mvnw verify            # tests (serveur HTTP simulé), contrat OpenAPI, Javadoc, SpotBugs, formatage
./mvnw spotless:apply    # formatage google-java-format
```

Numéros de test : voir le [README PHP](../php/README.fr.md#numéros-de-test).

## Licence

MIT — Labyrinthe Innovations SARL.
