<?php

declare(strict_types=1);

namespace EService\Partner\Webhook;

/**
 * Réponse eService après POST …/callback/.
 *
 * Le champ ``status`` renvoyé est le statut interne de l'étape
 * (``completed`` / ``failed`` / ``awaiting_external``).
 */
final class CallbackResponse
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed>|null $externalData
     * @param array<string, mixed>|null $externalApiByStep
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $runId,
        public readonly ?string $stepCode,
        public readonly ?string $status,
        public readonly bool $transitioned,
        public readonly ?array $externalData,
        public readonly ?array $externalApiByStep,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            ok: (bool) ($body['ok'] ?? false),
            runId: isset($body['run_id']) ? (string) $body['run_id'] : null,
            stepCode: isset($body['step_code']) ? (string) $body['step_code'] : null,
            status: isset($body['status']) ? (string) $body['status'] : null,
            transitioned: (bool) ($body['transitioned'] ?? false),
            externalData: isset($body['externalData']) && is_array($body['externalData'])
                ? $body['externalData']
                : null,
            externalApiByStep: isset($body['externalApiByStep']) && is_array($body['externalApiByStep'])
                ? $body['externalApiByStep']
                : null,
            raw: $body,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === CallbackStatus::Completed->value;
    }

    public function isFailed(): bool
    {
        return $this->status === CallbackStatus::Failed->value;
    }
}
