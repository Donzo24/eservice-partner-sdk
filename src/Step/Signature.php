<?php

declare(strict_types=1);

namespace EService\Partner\Step;

use EService\Partner\Client;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackResponse;
use EService\Partner\Webhook\ResultData;

final class Signature
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function confirm(
        IncomingDossier $dossier,
        ?ResultData $data = null,
        string $comment = '',
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        $code = $stepCode ?? $dossier->stepCode;
        if ($code === null || $code === '') {
            throw new EServiceException('stepCode is required for signature().');
        }

        $fields = $data?->toArray() ?? [];
        $payload = [
            'action' => 'sign',
            'motif' => $comment,
            'signatory_id' => (string) ($fields['signatoryId'] ?? ''),
            'signatory_name' => (string) ($fields['signatoryName'] ?? ''),
            'document_url' => (string) ($fields['documentUrl'] ?? ''),
        ];

        if ($dossier->runId && $this->client->getConfig()->baseUrl !== '') {
            try {
                return $this->client->completeStep($dossier->runId, $code, $payload);
            } catch (EServiceException) {
            }
        }

        return $this->client->callbackForDossier(
            $dossier,
            CallbackRequest::signature($fields, $comment),
            $callbackToken,
        );
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function reject(
        IncomingDossier $dossier,
        string $comment = '',
        ?ResultData $data = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        return $this->client->callbackForDossier(
            $dossier,
            CallbackRequest::rejected($comment !== '' ? $comment : 'Signature rejected', $data?->toArray(), [
                'kind' => 'signature',
                'motif' => $comment,
            ]),
            $callbackToken,
        );
    }
}
