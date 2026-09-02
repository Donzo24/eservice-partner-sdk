<?php

declare(strict_types=1);

namespace EService\Partner\Step;

use EService\Partner\Client;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackResponse;
use EService\Partner\Webhook\ResultData;

/**
 * Review step — partner API (preferred) or handoff webhook fallback.
 */
final class Review
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array<string, mixed>|CallbackResponse
     */
    public function submit(
        IncomingDossier $dossier,
        ReviewDecision $decision,
        string $comment = '',
        ?ResultData $data = null,
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        $code = $stepCode ?? $dossier->stepCode;
        if ($code === null || $code === '') {
            throw new EServiceException('stepCode is required for review().');
        }

        $payload = [
            'kind' => 'review',
            'decision' => $decision->value,
            'motif' => $comment,
        ];
        if ($data !== null && !$data->isEmpty()) {
            $payload['data'] = $data->toArray();
        }

        $cfg = $this->client->getConfig();
        if (
            $dossier->runId
            && $cfg->baseUrl !== ''
            && $cfg->partnerId
            && $cfg->partnerSecret
        ) {
            return $this->client->completeStep($dossier->runId, $code, $payload);
        }

        return $this->client->callbackForDossier(
            $dossier,
            CallbackRequest::review(
                $decision->value,
                $comment,
                $data?->isEmpty() === false ? $data->toArray() : null,
            ),
            $callbackToken,
        );
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function accept(
        IncomingDossier $dossier,
        string $comment = '',
        ?ResultData $data = null,
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        return $this->submit($dossier, ReviewDecision::Compliant, $comment, $data, $stepCode, $callbackToken);
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function reject(
        IncomingDossier $dossier,
        string $comment = '',
        ?ResultData $data = null,
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        return $this->submit($dossier, ReviewDecision::Rejected, $comment, $data, $stepCode, $callbackToken);
    }

    /** @return array<string, mixed>|CallbackResponse */
    public function requestComplement(
        IncomingDossier $dossier,
        string $comment = '',
        ?ResultData $data = null,
        ?string $stepCode = null,
        ?string $callbackToken = null,
    ): array|CallbackResponse {
        return $this->submit($dossier, ReviewDecision::Complement, $comment, $data, $stepCode, $callbackToken);
    }
}
