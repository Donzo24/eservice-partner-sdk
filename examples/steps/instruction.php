<?php

declare(strict_types=1);

/**
 * Example: instruction steps (English API names).
 *
 *   php examples/steps/instruction.php job.json review
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Webhook\ResultData;

$jobFile = $argv[1] ?? null;
$action = strtolower((string) ($argv[2] ?? 'review'));

if ($jobFile === null || !is_readable($jobFile)) {
    fwrite(STDERR, "Usage: php instruction.php <job.json> <review|approval|signature|document>\n");
    exit(1);
}

$stored = json_decode((string) file_get_contents($jobFile), true);
if (!is_array($stored) || !isset($stored['payload']) || !is_array($stored['payload'])) {
    fwrite(STDERR, "Invalid job file.\n");
    exit(1);
}

$payload = $stored['payload'];
if (!empty($stored['callbackUrl'])) {
    $payload['callbackUrl'] = $stored['callbackUrl'];
    $payload['callbackToken'] = $stored['callbackToken'] ?? null;
}

$dossier = IncomingDossier::fromPayload($payload);
$client = new Client(Config::fromArray([
    'baseUrl' => getenv('ESERVICE_BASE_URL') ?: '',
    'partnerId' => getenv('ESERVICE_PARTNER_ID') ?: null,
    'partnerSecret' => getenv('ESERVICE_PARTNER_SECRET') ?: null,
]));

$result = ResultData::make()
    ->numero('REG-2026-001')
    ->documentUrl('https://partenaire.example/docs/REG-2026-001.pdf');

try {
    $response = match ($action) {
        'review', 'controle', 'control' => $client->review()->accept(
            $dossier,
            'Dossier instruit et validé',
            $result,
        ),
        'approval', 'approbation' => $client->approval()->approve(
            $dossier,
            'Décision favorable',
            $result,
        ),
        'signature', 'sign' => $client->signature()->confirm(
            $dossier,
            ResultData::make()
                ->documentUrl('https://partenaire.example/docs/REG-2026-001.pdf')
                ->signedAt(gmdate('c'))
                ->signatoryName('Partner agent'),
            'Signed outside portal',
        ),
        'document', 'generation', 'document_generation' => $client->documentGeneration()->deliver(
            $dossier,
            $result,
            'Document produced',
        ),
        default => throw new InvalidArgumentException('Unknown action: ' . $action),
    };

    echo json_encode([
        'action' => $action,
        'ok' => $response->ok,
        'status' => $response->status,
        'transitioned' => $response->transitioned,
        'externalData' => $response->externalData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (EServiceException | InvalidArgumentException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
