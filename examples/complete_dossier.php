<?php

declare(strict_types=1);

/**
 * Example: complete a dossier via the instruction step API.
 *
 *   php examples/complete_dossier.php /tmp/eservice-partner-jobs/JOB-xxxx.json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Webhook\ResultData;

$jobFile = $argv[1] ?? null;
if ($jobFile === null || !is_readable($jobFile)) {
    fwrite(STDERR, "Usage: php complete_dossier.php <job.json>\n");
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

$data = ResultData::make()
    ->numero('REG-2026-001')
    ->documentUrl('https://partenaire.example/docs/REG-2026-001.pdf');

try {
    $response = match ($dossier->instructionStep) {
        \EService\Partner\Step\StepKind::Approval => $client->approval()->approve(
            $dossier,
            'Décision favorable',
            $data,
        ),
        \EService\Partner\Step\StepKind::Signature => $client->signature()->confirm(
            $dossier,
            ResultData::make()
                ->documentUrl('https://partenaire.example/signed.pdf')
                ->signedAt(gmdate('c')),
        ),
        \EService\Partner\Step\StepKind::DocumentGeneration => $client->documentGeneration()->deliver(
            $dossier,
            $data,
        ),
        default => $client->review()->accept(
            $dossier,
            'Dossier instruit et validé',
            $data,
        ),
    };

    echo json_encode([
        'ok' => $response->ok,
        'status' => $response->status,
        'transitioned' => $response->transitioned,
        'externalData' => $response->externalData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (EServiceException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
