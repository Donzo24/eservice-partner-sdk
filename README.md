# Partner PHP SDK — eService

SDK for **partner applications** that receive dossiers from eService and instruct them.

Not the agent or citizen SDK.

## Primary API (4 methods)

Toutes les méthodes prennent la **référence de la demande** (pas l’objet dossier).

| Method | Action |
|--------|--------|
| `$client->sendMessage($reference, …)` | Message au citoyen |
| `$client->sendDocument($reference, …)` | Document / résultat (étape « Résultat système externe ») |
| `$client->requestDocuments($reference, …)` | Demande de pièces complémentaires |
| `$client->validAppointment($reference, …)` | Valider ou refuser un rendez-vous |

```php
$client = new Client(Config::fromArray([
    'baseUrl' => getenv('ESERVICE_API_BASE'), // https://…/api/v1
    'partnerId' => getenv('ESERVICE_PARTNER_ID'),
    'partnerSecret' => getenv('ESERVICE_PARTNER_SECRET'),
]));

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
composer require guinee/eservice
```

Ou en développement local (path repository) :

```json
{
  "repositories": [{ "type": "path", "url": "../eservice/sdks/php" }],
  "require": { "guinee/eservice": "*" }
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
