<?php

declare(strict_types=1);

namespace EService\Partner\Webhook;

/**
 * JSON body for the eService partner callback webhook.
 *
 * Canonical order: status → kind → decision → motif → data
 *
 * Note: ``motif`` is the backend field name (sent as-is in JSON).
 */
final class CallbackRequest
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly CallbackStatus $status,
        public readonly ?array $data = null,
        public readonly ?string $decision = null,
        public readonly ?string $kind = null,
        public readonly ?string $motif = null,
        public readonly ?string $reason = null,
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array{decision?: string, kind?: string, motif?: string}|array<string, mixed> $options
     */
    public static function completed(?array $data = null, array $options = []): self
    {
        return new self(
            status: CallbackStatus::Completed,
            data: $data,
            decision: isset($options['decision']) ? (string) $options['decision'] : null,
            kind: isset($options['kind']) ? (string) $options['kind'] : null,
            motif: isset($options['motif']) ? (string) $options['motif'] : null,
            extra: self::stripKnown($options),
        );
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array{decision?: string, kind?: string, motif?: string}|array<string, mixed> $options
     */
    public static function failed(string $reason = '', ?array $data = null, array $options = []): self
    {
        return new self(
            status: CallbackStatus::Failed,
            data: $data,
            decision: isset($options['decision']) ? (string) $options['decision'] : null,
            kind: isset($options['kind']) ? (string) $options['kind'] : null,
            motif: isset($options['motif']) ? (string) $options['motif'] : null,
            reason: $reason !== '' ? $reason : null,
            extra: self::stripKnown($options),
        );
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array{decision?: string, kind?: string, motif?: string}|array<string, mixed> $options
     */
    public static function rejected(string $reason = '', ?array $data = null, array $options = []): self
    {
        return new self(
            status: CallbackStatus::Rejected,
            data: $data,
            decision: isset($options['decision']) ? (string) $options['decision'] : null,
            kind: isset($options['kind']) ? (string) $options['kind'] : null,
            motif: isset($options['motif']) ? (string) $options['motif'] : null,
            reason: $reason !== '' ? $reason : null,
            extra: self::stripKnown($options),
        );
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function progress(?array $data = null): self
    {
        return new self(status: CallbackStatus::Processing, data: $data);
    }

    /** Review step (agentBridge kind=review). */
    public static function review(
        string $decision,
        string $comment = '',
        ?array $data = null,
    ): self {
        return self::completed($data, [
            'kind' => 'review',
            'decision' => $decision,
            'motif' => $comment,
        ]);
    }

    /** Approval step (agentBridge kind=approval). */
    public static function approval(
        string $decision,
        string $comment = '',
        ?array $data = null,
    ): self {
        return self::completed($data, [
            'kind' => 'approval',
            'decision' => $decision,
            'motif' => $comment,
        ]);
    }

    /**
     * @param array<string, mixed> $signature
     */
    public static function signature(array $signature, string $comment = ''): self
    {
        return self::completed($signature, [
            'kind' => 'signature',
            'motif' => $comment,
            'decision' => 'signed',
        ]);
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function documentGeneration(array $document, string $comment = ''): self
    {
        return self::completed($document, [
            'kind' => 'document_generation',
            'motif' => $comment,
            'decision' => 'generated',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['status' => $this->status->value];
        if ($this->kind !== null && $this->kind !== '') {
            $body['kind'] = $this->kind;
        }
        if ($this->decision !== null && $this->decision !== '') {
            $body['decision'] = $this->decision;
        }
        if ($this->motif !== null && $this->motif !== '') {
            $body['motif'] = $this->motif;
        }
        if ($this->reason !== null && $this->reason !== '') {
            $body['reason'] = $this->reason;
        }
        if ($this->data !== null) {
            $body['data'] = $this->data;
        }

        return array_merge($body, $this->extra);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function stripKnown(array $options): array
    {
        unset($options['decision'], $options['kind'], $options['motif']);

        return $options;
    }
}
