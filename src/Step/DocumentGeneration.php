<?php

declare(strict_types=1);

namespace EService\Partner\Step;

use EService\Partner\Client;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackResponse;
use EService\Partner\Webhook\ResultData;

final class DocumentGeneration
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Generate via partner API (Doc Studio) or deliver partner-produced document via webhook.
     *
     * @return array<string, mixed>|CallbackResponse
     */
    public function deliver(
        IncomingDossier $dossier,
        ResultData $document,
        string $comment = '',
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        $code = $stepCode ?? $dossier->stepCode;
        if ($code === null || $code === '') {
            throw new EServiceException('stepCode is required for documentGeneration().');
        }

        if ($dossier->runId && $this->client->getConfig()->baseUrl !== '') {
            try {
                // Prefer native generation when calling partner API without a remote file.
                $fields = $document->toArray();
                if (empty($fields['documentUrl']) && empty($fields['contentBase64'])) {
                    return $this->client->partnerRequest(
                        'POST',
                        sprintf('/partner/runs/%s/document-generation/', rawurlencode($dossier->runId)),
                        ['step_code' => $code],
                    );
                }

                return $this->client->completeStep($dossier->runId, $code, [
                    'action' => 'generate',
                    'data' => $fields,
                    'motif' => $comment,
                ]);
            } catch (EServiceException) {
            }
        }

        return $this->client->callbackForDossier(
            $dossier,
            CallbackRequest::documentGeneration($document->toArray(), $comment),
            $callbackToken,
        );
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function fail(
        IncomingDossier $dossier,
        string $comment = '',
        ?ResultData $data = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        return $this->client->callbackForDossier(
            $dossier,
            CallbackRequest::failed(
                $comment !== '' ? $comment : 'Document generation failed',
                $data?->toArray(),
                ['kind' => 'document_generation', 'motif' => $comment],
            ),
            $callbackToken,
        );
    }
}
