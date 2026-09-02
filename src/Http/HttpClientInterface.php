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
     * @param array<string, mixed>|null $jsonBody
     * @return array{statusCode: int, body: array<string, mixed>|list<mixed>|null, raw: string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $jsonBody = null,
        int $timeoutSeconds = 30,
        bool $verifySsl = true,
    ): array;
}
