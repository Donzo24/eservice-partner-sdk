<?php

declare(strict_types=1);

/**
 * Example: reject a dossier (review reject).
 *
 *   php examples/reject_dossier.php /tmp/eservice-partner-jobs/JOB-xxxx.json "missing document"
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;

$jobFile = $argv[1] ?? null;
$reason = $argv[2] ?? 'Dossier rejected by partner';

if ($jobFile === null || !is_readable($jobFile)) {
    fwrite(STDERR, "Usage: php reject_dossier.php <job.json> [comment]\n");
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
    'partnerId' => getenv('ESERVICE_PARTNER_ID') ?: null,
    'partnerSecret' => getenv('ESERVICE_PARTNER_SECRET') ?: null,
]));

try {
    $response = $client->review()->reject($dossier, $reason);
    echo json_encode([
        'ok' => $response->ok,
        'status' => $response->status,
        'transitioned' => $response->transitioned,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (EServiceException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
