<?php

declare(strict_types=1);

namespace EService\Partner\Http;

use EService\Partner\Exception\EServiceException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeoutSeconds = 30,
        bool $verifySsl = true,
        string $bodyFormat = 'json',
    ): array {
        if (!extension_loaded('curl')) {
            throw new EServiceException('L\'extension PHP curl est requise.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new EServiceException('Impossible d\'initialiser cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ];

        if ($body !== null) {
            $format = strtolower(trim($bodyFormat)) === 'form' ? 'form' : 'json';
            if ($format === 'form') {
                $encoded = http_build_query($body);
                $opts[CURLOPT_POSTFIELDS] = $encoded;
                if (!$this->hasHeader($headers, 'Content-Type')) {
                    $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
                    $opts[CURLOPT_HTTPHEADER] = $headerLines;
                }
            } else {
                $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    curl_close($ch);
                    throw new EServiceException('Impossible d\'encoder le corps JSON.');
                }
                $opts[CURLOPT_POSTFIELDS] = $encoded;
                if (!$this->hasHeader($headers, 'Content-Type')) {
                    $headerLines[] = 'Content-Type: application/json';
                    $opts[CURLOPT_HTTPHEADER] = $headerLines;
                }
            }
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new EServiceException('Erreur HTTP cURL : ' . ($error ?: 'inconnu'));
        }

        $decoded = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = null;
            }
        }

        return [
            'statusCode' => $statusCode,
            'body' => $decoded,
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $key => $_value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
