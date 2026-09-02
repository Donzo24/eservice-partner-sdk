<?php

declare(strict_types=1);

namespace EService\Partner\Dossier;

use EService\Partner\Webhook\CallbackStatus;
use EService\Partner\Webhook\ResultData;

/**
 * Réponse de statut pour le polling eService (async.poll.url).
 *
 * Même enum ``CallbackStatus`` que le webhook callback.
 */
final class PollStatusResponse
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly CallbackStatus $status,
        public readonly ?ResultData $data = null,
        public readonly ?string $decision = null,
        public readonly ?string $kind = null,
        public readonly ?string $motif = null,
        public readonly array $extra = [],
    ) {
    }

    public static function awaiting(?ResultData $data = null): self
    {
        return new self(status: CallbackStatus::Processing, data: $data);
    }

    public static function completed(?ResultData $data = null, string $decision = '', string $kind = '', string $motif = ''): self
    {
        return new self(
            status: CallbackStatus::Completed,
            data: $data,
            decision: $decision !== '' ? $decision : null,
            kind: $kind !== '' ? $kind : null,
            motif: $motif !== '' ? $motif : null,
        );
    }

    public static function failed(?ResultData $data = null): self
    {
        return new self(status: CallbackStatus::Failed, data: $data);
    }

    public static function rejected(?ResultData $data = null): self
    {
        return new self(status: CallbackStatus::Rejected, data: $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = array_merge($this->extra, ['status' => $this->status->value]);
        if ($this->data !== null && !$this->data->isEmpty()) {
            $body['data'] = $this->data->toArray();
        }
        if ($this->decision !== null && $this->decision !== '') {
            $body['decision'] = $this->decision;
        }
        if ($this->kind !== null && $this->kind !== '') {
            $body['kind'] = $this->kind;
        }
        if ($this->motif !== null && $this->motif !== '') {
            $body['motif'] = $this->motif;
        }

        return $body;
    }
}
