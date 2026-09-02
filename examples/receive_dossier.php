<?php

declare(strict_types=1);

/**
 * Exemple : endpoint partenaire qui reçoit un dossier eService.
 *
 * Branchez cette logique sur la route configurée dans
 * metadata.external_api.endpointUrl du workflow.
 *
 * Usage (PHP built-in server) :
 *   php -S 0.0.0.0:8080 examples/receive_dossier.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\InvalidPayloadException;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST requis']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';

try {
    $dossier = IncomingDossier::fromPayload($raw);
} catch (InvalidPayloadException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'rejected', 'message' => $e->getMessage()]);
    exit;
}

// Persistez runId / stepCode / callbackUrl / callbackToken dans votre base.
$jobId = 'JOB-' . bin2hex(random_bytes(4));

// Exemple de stockage local (fichier) — remplacez par votre ORM.
$storageDir = sys_get_temp_dir() . '/eservice-partner-jobs';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}
file_put_contents(
    $storageDir . '/' . $jobId . '.json',
    json_encode([
        'jobId' => $jobId,
        'receivedAt' => gmdate('c'),
        'reference' => $dossier->reference,
        'runId' => $dossier->runId,
        'stepCode' => $dossier->stepCode,
        'callbackUrl' => $dossier->callbackUrl,
        'callbackToken' => $dossier->callbackToken,
        'citoyen' => $dossier->citoyen,
        'payload' => $dossier->raw,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

// Ack attendu par eService (correlationIdPath = body.jobId, ackPolicy successValues).
http_response_code(200);
echo json_encode($dossier->ackResponse($jobId, 'accepted'), JSON_UNESCAPED_UNICODE);
