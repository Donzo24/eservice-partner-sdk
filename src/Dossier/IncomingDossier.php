<?php

declare(strict_types=1);

namespace EService\Partner\Dossier;

use EService\Partner\Exception\InvalidPayloadException;
use EService\Partner\Step\StepKind;

/**
 * Dossier reçu depuis eService (envoi initial external_handoff).
 *
 * Le corps JSON est configurable par workflow ; ce modèle expose les champs
 * usuels (référence, citoyen, callback, étape d'instruction attendue).
 */
final class IncomingDossier
{
    /**
     * @param array<string, mixed> $raw
     * @param array{commune?: array<string, mixed>, prefecture?: array<string, mixed>, region?: array<string, mixed>, country?: array<string, mixed>}|null $territory
     * @param array<string, mixed>|null $citoyen
     */
    public function __construct(
        public readonly array $raw,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $callbackToken = null,
        public readonly ?string $runId = null,
        public readonly ?string $stepCode = null,
        public readonly ?string $reference = null,
        public readonly ?string $partnerId = null,
        public readonly ?string $partnerSecret = null,
        public readonly ?array $citoyen = null,
        public readonly ?array $territory = null,
        public readonly ?StepKind $instructionStep = null,
    ) {
    }

    /**
     * Parse le JSON reçu sur l'endpoint partenaire.
     *
     * @param array<string, mixed>|string $payload Corps JSON décodé ou chaîne JSON
     */
    public static function fromPayload(array|string $payload): self
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                throw new InvalidPayloadException('Le corps reçu n\'est pas un objet JSON valide.');
            }
            $payload = $decoded;
        }

        $callbackUrl = self::stringOrNull(
            $payload['callbackUrl']
            ?? $payload['callback_url']
            ?? null
        );
        $callbackToken = self::stringOrNull(
            $payload['callbackToken']
            ?? $payload['callback_token']
            ?? null
        );
        $partnerId = self::stringOrNull($payload['partnerId'] ?? $payload['partner_id'] ?? null);
        $partnerSecret = self::stringOrNull($payload['partnerSecret'] ?? $payload['partner_secret'] ?? null);

        $runId = self::stringOrNull($payload['runId'] ?? $payload['run_id'] ?? null);
        $stepCode = self::stringOrNull($payload['stepCode'] ?? $payload['step_code'] ?? null);

        if ($callbackUrl !== null && ($runId === null || $stepCode === null)) {
            $parsed = self::parseCallbackUrl($callbackUrl);
            $runId ??= $parsed['runId'];
            $stepCode ??= $parsed['stepCode'];
        }

        $reference = self::stringOrNull(
            $payload['dossierRef']
            ?? $payload['reference']
            ?? $payload['reference_eservice']
            ?? null
        );

        $citoyen = null;
        if (isset($payload['citoyen']) && is_array($payload['citoyen'])) {
            $citoyen = $payload['citoyen'];
        } elseif (
            isset($payload['familyName']) || isset($payload['givenName'])
            || isset($payload['email']) || isset($payload['nin'])
        ) {
            $citoyen = array_filter([
                'nom' => $payload['familyName'] ?? null,
                'prenom' => $payload['givenName'] ?? null,
                'email' => $payload['email'] ?? null,
                'nin' => $payload['nin'] ?? null,
                'telephone' => $payload['telephone'] ?? $payload['phone'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        $territory = isset($payload['territory']) && is_array($payload['territory'])
            ? $payload['territory']
            : null;

        $instructionStep = StepKind::tryFromPayload(
            $payload['instructionStep']
            ?? $payload['instruction_step']
            ?? $payload['stepKind']
            ?? $payload['step_kind']
            ?? $payload['action']
            ?? null
        );

        return new self(
            raw: $payload,
            callbackUrl: $callbackUrl,
            callbackToken: $callbackToken,
            runId: $runId,
            stepCode: $stepCode,
            reference: $reference,
            partnerId: $partnerId,
            partnerSecret: $partnerSecret,
            citoyen: $citoyen,
            territory: $territory,
            instructionStep: $instructionStep,
        );
    }

    /**
     * Extrait runId (+ stepCode legacy) depuis une URL de callback eService.
     *
     * Patterns :
     * - /api/v1/workflows/portail/runs/{uuid}/callback/          (demande)
     * - /api/v1/partner/runs/{uuid}/callback/                    (demande)
     * - /api/v1/workflows/portail/runs/{uuid}/external-api/{step}/callback/  (legacy étape)
     *
     * @return array{runId: ?string, stepCode: ?string}
     */
    public static function parseCallbackUrl(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        // Niveau demande (nouveau)
        if (preg_match(
            '#/(?:workflows/)?portail/runs/([0-9a-fA-F-]{36})/callback/?$#',
            $path,
            $m
        )) {
            return ['runId' => $m[1], 'stepCode' => null];
        }
        if (preg_match(
            '#/partner/runs/([0-9a-fA-F-]{36})/callback/?$#',
            $path,
            $m
        )) {
            return ['runId' => $m[1], 'stepCode' => null];
        }

        // Legacy étape
        if (preg_match(
            '#/portail/runs/([0-9a-fA-F-]{36})/external-api/([^/]+)/callback/?$#',
            $path,
            $m
        )) {
            return ['runId' => $m[1], 'stepCode' => $m[2]];
        }

        return ['runId' => null, 'stepCode' => null];
    }

    /**
     * Accès à une clé du payload (support notation pointée : citoyen.email).
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $parts = explode('.', $path);
        $cursor = $this->raw;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * Interprète une valeur du payload comme fichier base64 (mode |file:content).
     */
    public function file(string $path): ?FileAttachment
    {
        $value = $this->get($path);
        if (!is_array($value)) {
            return null;
        }
        if (!isset($value['contentBase64']) && !isset($value['content_base64'])) {
            return null;
        }

        return FileAttachment::fromArray($value);
    }

    /**
     * Réponse d'acquittement attendue par eService (correlationIdPath typique : body.jobId).
     *
     * @return array{jobId: string, status: string}
     */
    public function ackResponse(string $jobId, string $status = 'accepted'): array
    {
        return [
            'jobId' => $jobId,
            'status' => $status,
        ];
    }

    public function canCallback(): bool
    {
        return $this->callbackUrl !== null && $this->callbackUrl !== '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
