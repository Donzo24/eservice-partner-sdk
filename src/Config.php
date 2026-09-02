<?php

declare(strict_types=1);

namespace EService\Partner;

/**
 * Configuration du SDK partenaire eService.
 *
 * Authentification webhook uniquement :
 * - ``callbackToken`` du dossier (header X-Webhook-Token), et/ou
 * - ``partnerId`` + ``partnerSecret`` configurés sur l'étape de transfert.
 */
final class Config
{
    public function __construct(
        public readonly string $baseUrl = '',
        public readonly ?string $partnerId = null,
        public readonly ?string $partnerSecret = null,
        public readonly string $webhookTokenHeader = 'X-Webhook-Token',
        public readonly int $timeoutSeconds = 30,
        public readonly bool $verifySsl = true,
    ) {
    }

    /**
     * @param array{
     *   baseUrl?: string,
     *   partnerId?: string|null,
     *   partnerSecret?: string|null,
     *   webhookTokenHeader?: string,
     *   timeoutSeconds?: int,
     *   verifySsl?: bool
     * } $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            baseUrl: rtrim((string) ($options['baseUrl'] ?? ''), '/'),
            partnerId: isset($options['partnerId']) ? (string) $options['partnerId'] : null,
            partnerSecret: isset($options['partnerSecret']) ? (string) $options['partnerSecret'] : null,
            webhookTokenHeader: (string) ($options['webhookTokenHeader'] ?? 'X-Webhook-Token'),
            timeoutSeconds: (int) ($options['timeoutSeconds'] ?? 30),
            verifySsl: (bool) ($options['verifySsl'] ?? true),
        );
    }

    public function withBaseUrl(string $baseUrl): self
    {
        return new self(
            baseUrl: rtrim($baseUrl, '/'),
            partnerId: $this->partnerId,
            partnerSecret: $this->partnerSecret,
            webhookTokenHeader: $this->webhookTokenHeader,
            timeoutSeconds: $this->timeoutSeconds,
            verifySsl: $this->verifySsl,
        );
    }
}
