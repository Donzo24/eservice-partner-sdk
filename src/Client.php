<?php

declare(strict_types=1);

namespace EService\Partner;

use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\ApiException;
use EService\Partner\Exception\AuthenticationException;
use EService\Partner\Exception\EServiceException;
use EService\Partner\Http\CurlHttpClient;
use EService\Partner\Http\HttpClientInterface;
use EService\Partner\Step\Approval;
use EService\Partner\Step\DocumentGeneration;
use EService\Partner\Step\Review;
use EService\Partner\Step\Signature;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackResponse;
use EService\Partner\Webhook\ResultData;

/**
 * Partner SDK — external agent layer over eService.
 *
 * Primary API:
 * 1. sendMessage()
 * 2. sendDocument()
 * 3. requestDocuments()
 * 4. validAppointment()
 * 5. completeDemand()
 *
 * Legacy helpers (review / approval / signature / documentGeneration / callback)
 * remain available for older integrations.
 */
final class Client
{
    private Config $config;
    private HttpClientInterface $http;
    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(?Config $config = null, ?HttpClientInterface $http = null)
    {
        $this->config = $config ?? new Config();
        $this->http = $http ?? new CurlHttpClient();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Send a message to the citizen on the dossier thread.
     *
     * @param string $reference Référence de la demande (ex. DEM-2026-00042)
     * @param list<array{url?: string, name?: string}>|null $attachments
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $reference,
        string $message,
        ?array $attachments = null,
    ): array {
        $ref = trim($reference);
        if ($ref === '') {
            throw new EServiceException('La référence de la demande est requise.');
        }

        // Un seul appel APIM-friendly (évite GET by-reference + POST /runs/{uuid}/…).
        $payload = [
            'reference' => $ref,
            'body' => $message,
        ];
        if ($attachments !== null) {
            $payload['attachments'] = $attachments;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/by-reference/message/',
            $payload,
        );
    }

    /**
     * Deliver a document / result payload for the « Résultat système externe » step.
     *
     * @param string $reference Référence de la demande
     * @return array<string, mixed>
     */
    public function sendDocument(
        string $reference,
        ResultData|array|string|\SplFileInfo $document,
        string $message = '',
    ): array {
        $ref = trim($reference);
        if ($ref === '') {
            throw new EServiceException('La référence de la demande est requise.');
        }

        $localFile = null;
        $metadata = [];
        if (is_string($document) || $document instanceof \SplFileInfo) {
            $localFile = $document;
        } elseif (is_array($document) && isset($document['file'])) {
            $localFile = $document['file'];
            $metadata = $document;
            unset($metadata['file']);
        } elseif (is_array($document) && isset($document['path'])) {
            $localFile = $document['path'];
            $metadata = $document;
            unset($metadata['path']);
        }

        if ($localFile !== null) {
            if (!is_string($localFile) && !$localFile instanceof \SplFileInfo) {
                throw new EServiceException('Le champ file doit être un chemin local ou un SplFileInfo.');
            }
            return $this->uploadDocument($ref, $localFile, $metadata, $message);
        }

        $data = $document instanceof ResultData ? $document->toArray() : $document;
        $payload = ['reference' => $ref, 'data' => $data];
        if ($message !== '') {
            $payload['message'] = $message;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/by-reference/document/',
            $payload,
        );
    }

    /**
     * Ask the citizen for complementary documents.
     *
     * @param string $reference Référence de la demande
     * @param list<string|array{label?: string}> $items
     * @return array<string, mixed>
     */
    public function requestDocuments(
        string $reference,
        array $items,
        string $message = '',
    ): array {
        $runId = $this->resolveRunIdFromReference($reference);
        $payload = ['items' => $items];
        if ($message !== '') {
            $payload['message'] = $message;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/' . rawurlencode($runId) . '/request-documents/',
            $payload,
        );
    }

    /**
     * Validate or reject a citizen appointment slot.
     *
     * @param string $reference Référence de la demande
     * @param 'approve'|'reject'|'valid' $action
     * @return array<string, mixed>
     */
    public function validAppointment(
        string $reference,
        string $stepCode,
        string $action = 'approve',
        string $rejectionReason = '',
    ): array {
        $runId = $this->resolveRunIdFromReference($reference);
        $payload = [
            'step_code' => $stepCode,
            'action' => $action,
        ];
        if ($rejectionReason !== '') {
            $payload['rejection_reason'] = $rejectionReason;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/' . rawurlencode($runId) . '/validate-appointment/',
            $payload,
        );
    }

    /**
     * Mark the demand as completed (state_data.phase = done).
     *
     * Idempotent if already completed. Optional citizen message uses the same
     * channel as sendMessage.
     *
     * @param string $reference Référence de la demande (ex. DEM-2026-00042)
     * @return array<string, mixed>
     */
    public function completeDemand(string $reference, string $message = ''): array
    {
        $ref = trim($reference);
        if ($ref === '') {
            throw new EServiceException('La référence de la demande est requise.');
        }

        $payload = ['reference' => $ref];
        if ($message !== '') {
            $payload['message'] = $message;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/by-reference/complete/',
            $payload,
        );
    }

    /** @deprecated Prefer sendMessage / sendDocument / requestDocuments / validAppointment / completeDemand */
    public function review(): Review
    {
        return new Review($this);
    }

    /** @deprecated Prefer sendMessage / sendDocument / requestDocuments / validAppointment */
    public function approval(): Approval
    {
        return new Approval($this);
    }

    /** @deprecated Prefer sendDocument() */
    public function signature(): Signature
    {
        return new Signature($this);
    }

    /** @deprecated Prefer sendDocument() */
    public function documentGeneration(): DocumentGeneration
    {
        return new DocumentGeneration($this);
    }

    public function callbackUrl(string $referenceOrRunId): string
    {
        $base = $this->requireBaseUrl();
        $key = trim($referenceOrRunId);
        if ($key === '') {
            throw new EServiceException('La référence (ou runId) est requise pour callbackUrl().');
        }

        // UUID → URL run directe ; sinon URL by-reference
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $key) === 1) {
            return sprintf('%s/partner/runs/%s/callback/', $base, rawurlencode($key));
        }

        return sprintf(
            '%s/partner/runs/by-reference/callback/?reference=%s',
            $base,
            rawurlencode($key),
        );
    }

    /**
     * Webhook / callback au niveau de la demande (plus lié à une étape).
     *
     * @throws AuthenticationException
     * @throws ApiException
     */
    public function callback(
        string $reference,
        CallbackRequest $request,
        ?string $callbackToken = null,
        ?string $callbackUrl = null,
    ): CallbackResponse {
        if ($callbackUrl) {
            $response = $this->http->request(
                'POST',
                $callbackUrl,
                $this->buildWebhookAuthHeaders($callbackToken),
                $request->toArray(),
                $this->config->timeoutSeconds,
                $this->config->sslVerifyOption(),
            );

            return $this->handleJsonResponse($response, asCallback: true);
        }

        // Canal préféré : API partenaire (auth X-Partner-Id / Secret), résolu par référence
        $payload = $request->toArray();
        $payload['reference'] = trim($reference);
        $result = $this->partnerRequest(
            'POST',
            '/partner/runs/by-reference/callback/',
            $payload,
        );

        return CallbackResponse::fromArray($result);
    }

    /**
     * @throws AuthenticationException
     * @throws ApiException
     */
    public function callbackForDossier(
        IncomingDossier $dossier,
        CallbackRequest $request,
        ?string $callbackToken = null,
    ): CallbackResponse {
        $token = $callbackToken ?? $dossier->callbackToken;
        if ($dossier->callbackUrl) {
            return $this->callback(
                reference: $dossier->reference ?? $dossier->runId ?? '',
                request: $request,
                callbackToken: $token,
                callbackUrl: $dossier->callbackUrl,
            );
        }
        $reference = $dossier->reference;
        if ($reference === null || $reference === '') {
            if ($dossier->runId) {
                $runId = $dossier->runId;
                $result = $this->partnerRequest(
                    'POST',
                    '/partner/runs/' . rawurlencode($runId) . '/callback/',
                    $request->toArray(),
                );

                return CallbackResponse::fromArray($result);
            }
            throw new EServiceException('Missing reference / callbackUrl / runId on dossier.');
        }

        return $this->callback($reference, $request, $token);
    }

    /**
     * GET /partner/runs/{id}/
     *
     * @return array<string, mixed>
     */
    public function getRun(string $runId): array
    {
        return $this->partnerRequest('GET', '/partner/runs/' . rawurlencode($runId) . '/');
    }

    /**
     * GET /partner/runs/by-reference/?reference=
     *
     * @return array<string, mixed>
     */
    public function getRunByReference(string $reference): array
    {
        return $this->partnerRequest(
            'GET',
            '/partner/runs/by-reference/?reference=' . rawurlencode($reference),
        );
    }

    /**
     * POST /partner/runs/{id}/steps/{step}/complete/
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function completeStep(string $runId, string $stepCode, array $payload): array
    {
        return $this->partnerRequest(
            'POST',
            sprintf(
                '/partner/runs/%s/steps/%s/complete/',
                rawurlencode($runId),
                rawurlencode($stepCode),
            ),
            $payload,
        );
    }

    /**
     * Typed review/approval/handoff/document/signature via partner API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|CallbackResponse
     */
    public function instruct(
        IncomingDossier $dossier,
        string $stepCode,
        array $payload,
    ): array|CallbackResponse {
        $runId = $dossier->runId;
        if ($runId && $this->config->baseUrl !== '' && $this->hasPartnerCredentials()) {
            return $this->completeStep($runId, $stepCode, $payload);
        }

        $request = CallbackRequest::completed(
            isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null,
            [
                'kind' => (string) ($payload['kind'] ?? ''),
                'decision' => (string) ($payload['decision'] ?? ''),
                'motif' => (string) ($payload['motif'] ?? $payload['comment'] ?? ''),
            ],
        );

        return $this->callbackForDossier($dossier, $request);
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    public function partnerRequest(
        string $method,
        string $path,
        ?array $jsonBody = null,
        string $bodyFormat = 'json',
    ): array
    {
        $url = $this->requireBaseUrl() . '/' . ltrim($path, '/');
        $headers = $this->buildPartnerAuthHeaders();
        if ($bodyFormat === 'multipart') {
            unset($headers['Content-Type']);
        }
        $response = $this->http->request(
            $method,
            $url,
            $headers,
            $jsonBody,
            $this->config->timeoutSeconds,
            $this->config->sslVerifyOption(),
            $bodyFormat,
        );
        $result = $this->handleJsonResponse($response, asCallback: false);

        return is_array($result) ? $result : $result->raw;
    }

    /**
     * @param string|\SplFileInfo $file
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function uploadDocument(
        string $reference,
        string|\SplFileInfo $file,
        array $metadata,
        string $message,
    ): array {
        $path = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
        if (!is_file($path)) {
            throw new EServiceException('Le document à envoyer est introuvable : ' . $path);
        }
        if (!is_readable($path)) {
            throw new EServiceException('Le document à envoyer est illisible : ' . $path);
        }
        $size = filesize($path);
        if ($size === false || $size > 15 * 1024 * 1024) {
            throw new EServiceException('Le document dépasse la taille maximale autorisée de 15 Mo.');
        }

        $filename = isset($metadata['filename']) && trim((string) $metadata['filename']) !== ''
            ? basename((string) $metadata['filename'])
            : basename($path);
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        $contentType = is_string($mime) && $mime !== ''
            ? $mime
            : 'application/octet-stream';

        unset($metadata['filename'], $metadata['contentType']);
        $payload = [
            'reference' => $reference,
            'file' => new \CURLFile($path, $contentType, $filename),
        ];
        if ($message !== '') {
            $payload['message'] = $message;
        }
        if ($metadata !== []) {
            $encodedMetadata = json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if ($encodedMetadata === false) {
                throw new EServiceException('Impossible d\'encoder les métadonnées du document.');
            }
            $payload['data'] = $encodedMetadata;
        }

        return $this->partnerRequest(
            'POST',
            '/partner/runs/by-reference/document/',
            $payload,
            'multipart',
        );
    }

    private function resolveRunIdFromReference(string $reference): string
    {
        $ref = trim($reference);
        if ($ref === '') {
            throw new EServiceException('La référence de la demande est requise.');
        }

        $run = $this->getRunByReference($ref);
        $id = $run['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new EServiceException(
                'Impossible de résoudre la demande pour la référence « ' . $ref . ' ».'
            );
        }

        return $id;
    }

    private function requireBaseUrl(): string
    {
        if ($this->config->baseUrl === '') {
            throw new EServiceException('Config::$baseUrl is required (e.g. https://host/api/v1).');
        }

        return $this->config->baseUrl;
    }

    private function hasPartnerCredentials(): bool
    {
        return $this->config->partnerId !== null
            && $this->config->partnerId !== ''
            && $this->config->partnerSecret !== null
            && $this->config->partnerSecret !== '';
    }

    /**
     * Obtient un access_token Keycloak (client_credentials), avec cache mémoire.
     *
     * @throws AuthenticationException
     * @throws ApiException
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (!$this->config->hasOAuthConfig()) {
            throw new AuthenticationException(
                'OAuth non configuré : renseignez oauthTokenUrl, oauthClientId et oauthClientSecret (.env).'
            );
        }

        $now = time();
        if (
            !$forceRefresh
            && $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - $this->config->oauthSkewSeconds)
        ) {
            return $this->cachedAccessToken;
        }

        $response = $this->http->request(
            'POST',
            (string) $this->config->oauthTokenUrl,
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'eservice-partner-sdk-php/2.1',
            ],
            [
                'grant_type' => $this->config->oauthGrantType !== ''
                    ? $this->config->oauthGrantType
                    : 'client_credentials',
                'client_id' => (string) $this->config->oauthClientId,
                'client_secret' => (string) $this->config->oauthClientSecret,
            ],
            $this->config->timeoutSeconds,
            $this->config->sslVerifyOption(),
            'form',
        );

        $code = $response['statusCode'];
        $body = is_array($response['body']) ? $response['body'] : [];
        if ($code < 200 || $code >= 300) {
            throw new AuthenticationException(
                is_string($body['error_description'] ?? null)
                    ? (string) $body['error_description']
                    : (is_string($body['error'] ?? null)
                        ? (string) $body['error']
                        : ('Échec obtention token OAuth (HTTP ' . $code . ').'))
            );
        }

        $token = $body['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new AuthenticationException('Réponse OAuth sans access_token.');
        }

        $expiresIn = isset($body['expires_in']) ? (int) $body['expires_in'] : 300;
        if ($expiresIn < 30) {
            $expiresIn = 30;
        }

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + $expiresIn;

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function buildPartnerAuthHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'eservice-partner-sdk-php/2.1',
        ];
        if ($this->hasPartnerCredentials()) {
            $headers['X-Partner-Id'] = (string) $this->config->partnerId;
            $headers['X-Partner-Secret'] = (string) $this->config->partnerSecret;
        } else {
            throw new AuthenticationException(
                'Partner credentials (partnerId / partnerSecret) are required for /partner API.'
            );
        }

        if ($this->config->hasOAuthConfig()) {
            $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function buildWebhookAuthHeaders(?string $callbackToken): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'eservice-partner-sdk-php/2.1',
        ];
        $token = $callbackToken !== null && $callbackToken !== '' ? $callbackToken : null;
        if ($token !== null) {
            $headers[$this->config->webhookTokenHeader] = $token;
        }
        if ($this->hasPartnerCredentials()) {
            $headers['X-Partner-Id'] = (string) $this->config->partnerId;
            $headers['X-Partner-Secret'] = (string) $this->config->partnerSecret;
        }
        if ($this->config->hasOAuthConfig()) {
            $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
        }
        if ($token === null && !$this->hasPartnerCredentials() && !$this->config->hasOAuthConfig()) {
            throw new AuthenticationException('No callback token or partner credentials provided.');
        }

        return $headers;
    }

    /**
     * @param array{statusCode: int, body: array<string, mixed>|list<mixed>|null, raw: string} $response
     */
    private function handleJsonResponse(array $response, bool $asCallback): array|CallbackResponse
    {
        $code = $response['statusCode'];
        $body = is_array($response['body']) ? $response['body'] : [];
        /** @var array<string, mixed> $assoc */
        $assoc = $body;

        if ($code === 401 || $code === 403) {
            throw new AuthenticationException(
                (string) ($assoc['detail'] ?? 'Partner authentication failed.')
            );
        }
        if ($code < 200 || $code >= 300) {
            throw new ApiException(
                is_string($assoc['detail'] ?? null)
                    ? (string) $assoc['detail']
                    : ('eService API error HTTP ' . $code),
                $code,
                $assoc,
            );
        }

        return $asCallback ? CallbackResponse::fromArray($assoc) : $assoc;
    }
}
