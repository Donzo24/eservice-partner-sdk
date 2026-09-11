# Partner PHP SDK — eService

SDK for **partner applications** that receive dossiers from eService and instruct them.

Not the agent or citizen SDK.

## Auth (APIM / Keycloak)

Avant chaque appel e-Service, le SDK peut obtenir un token OAuth2 `client_credentials` :

1. `POST {ESERVICE_OAUTH_TOKEN_URL}` (form-urlencoded)
2. Appel e-Service avec :
   - `Authorization: Bearer <access_token>`
   - `X-Partner-Id` / `X-Partner-Secret`

Toutes les valeurs viennent du `.env` partenaire.

```bash
ESERVICE_API_BASE=https://extapi.service-public.gov.gn/v2
ESERVICE_PARTNER_ID=prt_xxx
ESERVICE_PARTNER_SECRET=change-me
ESERVICE_OAUTH_TOKEN_URL=https://iamapim.service-public.gov.gn/realms/kong/protocol/openid-connect/token
ESERVICE_OAUTH_CLIENT_ID=your-client-id
ESERVICE_OAUTH_CLIENT_SECRET=your-client-secret
ESERVICE_OAUTH_GRANT_TYPE=client_credentials
ESERVICE_VERIFY_SSL=true
```

```php
$client = new Client(Config::fromArray([
    'baseUrl' => getenv('ESERVICE_API_BASE'),
    'partnerId' => getenv('ESERVICE_PARTNER_ID'),
    'partnerSecret' => getenv('ESERVICE_PARTNER_SECRET'),
    'oauthTokenUrl' => getenv('ESERVICE_OAUTH_TOKEN_URL'),
    'oauthClientId' => getenv('ESERVICE_OAUTH_CLIENT_ID'),
    'oauthClientSecret' => getenv('ESERVICE_OAUTH_CLIENT_SECRET'),
    'oauthGrantType' => getenv('ESERVICE_OAUTH_GRANT_TYPE') ?: 'client_credentials',
    'verifySsl' => filter_var(getenv('ESERVICE_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOL),
]));

$client->callback('DEM-2026-00042', CallbackRequest::completed(['numero' => 'REG-1']));
```

Sans variables OAuth, le SDK conserve le comportement historique (headers partenaire seuls — utile en local hors APIM).

## Primary API (4 methods)

Toutes les méthodes prennent la **référence de la demande** (pas l’objet dossier).

| Method | Action |
|--------|--------|
| `$client->sendMessage($reference, …)` | Message au citoyen |
| `$client->sendDocument($reference, …)` | Document / résultat (étape « Résultat système externe ») |
| `$client->requestDocuments($reference, …)` | Demande de pièces + **remise à la 1re étape** (citoyen peut modifier / resoumettre) |
| `$client->validAppointment($reference, …)` | Valider ou refuser un rendez-vous |

```php
$reference = 'DEM-2026-00042';

$client->sendMessage($reference, 'Votre dossier est en cours de traitement.');
$client->sendDocument(
    $reference,
    ResultData::make()->numero('REG-2026-001')->documentUrl('https://…/doc.pdf'),
);
$client->requestDocuments($reference, ['CNI', 'Justificatif de domicile'], 'Merci de compléter.');
$client->validAppointment($reference, 'rdv', 'approve');

// Callback webhook (demande entière — plus lié à une étape)
$client->callback($reference, CallbackRequest::completed(['numero' => 'REG-1']));
```

Le SDK résout la référence via `GET /partner/runs/by-reference/?reference=…` puis appelle l’endpoint d’action.
Le callback utilise `POST /partner/runs/by-reference/callback/`.

Legacy helpers (`review()`, `approval()`, `signature()`, `documentGeneration()`, webhook `callback`) remain available.

## Installation

Via [Packagist](https://packagist.org/packages/guinee/eservice) :

```bash
composer require guinee/eservice:^2.1
```

Ou en développement local (path repository) :

```json
{
  "repositories": [{ "type": "path", "url": "../eservice/sdks/php" }],
  "require": { "guinee/eservice": "2.1.*" }
}
```

PHP ≥ 8.1, `ext-curl`, `ext-json`.

## Receive a dossier

```php
use EService\Partner\Dossier\IncomingDossier;

$dossier = IncomingDossier::fromPayload(file_get_contents('php://input'));
$reference = $dossier->reference; // utiliser ensuite dans sendMessage / sendDocument / …

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($dossier->ackResponse('JOB-' . uniqid(), 'accepted'));
```

## Tests

```bash
composer test
```
