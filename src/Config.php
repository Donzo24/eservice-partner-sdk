<?php

declare(strict_types=1);

namespace EService\Partner;

/**
 * Configuration du SDK partenaire eService.
 *
 * Authentification des appels sortants vers e-Service / APIM :
 * 1. (recommandé prod) OAuth2 client_credentials (Keycloak / IAM APIM)
 *    → header ``Authorization: Bearer <access_token>``
 * 2. Identifiants partenaire → ``X-Partner-Id`` / ``X-Partner-Secret``
 * 3. (legacy webhook) ``callbackToken`` → header configurable (défaut X-Webhook-Token)
 *
 * Toutes les valeurs doivent venir du ``.env`` du partenaire.
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
        /** URL token OpenID Connect, ex. …/realms/kong/protocol/openid-connect/token */
        public readonly ?string $oauthTokenUrl = null,
        public readonly ?string $oauthClientId = null,
        public readonly ?string $oauthClientSecret = null,
        public readonly string $oauthGrantType = 'client_credentials',
        /** Marge (secondes) avant ``expires_in`` pour rafraîchir le token. */
        public readonly int $oauthSkewSeconds = 60,
    ) {
    }

    /**
     * @param array{
     *   baseUrl?: string,
     *   partnerId?: string|null,
     *   partnerSecret?: string|null,
     *   webhookTokenHeader?: string,
     *   timeoutSeconds?: int,
     *   verifySsl?: bool,
     *   oauthTokenUrl?: string|null,
     *   oauthClientId?: string|null,
     *   oauthClientSecret?: string|null,
     *   oauthGrantType?: string,
     *   oauthSkewSeconds?: int
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
            oauthTokenUrl: isset($options['oauthTokenUrl']) && (string) $options['oauthTokenUrl'] !== ''
                ? (string) $options['oauthTokenUrl']
                : null,
            oauthClientId: isset($options['oauthClientId']) && (string) $options['oauthClientId'] !== ''
                ? (string) $options['oauthClientId']
                : null,
            oauthClientSecret: isset($options['oauthClientSecret']) && (string) $options['oauthClientSecret'] !== ''
                ? (string) $options['oauthClientSecret']
                : null,
            oauthGrantType: (string) ($options['oauthGrantType'] ?? 'client_credentials'),
            oauthSkewSeconds: max(0, (int) ($options['oauthSkewSeconds'] ?? 60)),
        );
    }

    public function hasOAuthConfig(): bool
    {
        return $this->oauthTokenUrl !== null
            && $this->oauthTokenUrl !== ''
            && $this->oauthClientId !== null
            && $this->oauthClientId !== ''
            && $this->oauthClientSecret !== null
            && $this->oauthClientSecret !== '';
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
            oauthTokenUrl: $this->oauthTokenUrl,
            oauthClientId: $this->oauthClientId,
            oauthClientSecret: $this->oauthClientSecret,
            oauthGrantType: $this->oauthGrantType,
            oauthSkewSeconds: $this->oauthSkewSeconds,
        );
    }
}
