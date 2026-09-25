<?php

declare(strict_types=1);

namespace EService\Partner\Http;

/**
 * Client HTTP minimal (interface) — permet d'injecter Guzzle ou un mock en tests.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body Corps JSON, formulaire ou multipart
     * @param bool|string $verifySsl false = off ; true = CA système ; string = chemin PEM
     * @param 'json'|'form'|'multipart' $bodyFormat
     * @return array{statusCode: int, body: array<string, mixed>|list<mixed>|null, raw: string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeoutSeconds = 30,
        bool|string $verifySsl = true,
        string $bodyFormat = 'json',
    ): array;
}
