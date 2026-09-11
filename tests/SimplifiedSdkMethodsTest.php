<?php

declare(strict_types=1);

namespace EService\Partner\Tests;

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Http\HttpClientInterface;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\ResultData;
use PHPUnit\Framework\TestCase;

final class SimplifiedSdkMethodsTest extends TestCase
{
    private const REFERENCE = 'DEM-2026-00042';
    private const RUN_ID = '550e8400-e29b-41d4-a716-446655440000';

    private function clientWithCapture(object $http, array $configExtra = []): Client
    {
        return new Client(Config::fromArray(array_merge([
            'baseUrl' => 'https://api.example/api/v1',
            'partnerId' => 'prt_test',
            'partnerSecret' => 'secret',
        ], $configExtra)), $http);
    }

    /**
     * HTTP stub: first call resolves reference → run id, subsequent calls are the action.
     */
    private function httpStub(): object
    {
        return new class(self::RUN_ID) implements HttpClientInterface {
            /** @var list<array{0:string,1:string,2:array,3:array<string,string>,4:string}> */
            public array $calls = [];

            public function __construct(private readonly string $runId)
            {
            }

            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?array $body = null,
                int $timeoutSeconds = 30,
                bool $verifySsl = true,
                string $bodyFormat = 'json',
            ): array {
                $this->calls[] = [$method, $url, $body ?? [], $headers, $bodyFormat];
                if (str_contains($url, '/openid-connect/token')) {
                    return [
                        'statusCode' => 200,
                        'body' => ['access_token' => 'tok_test', 'expires_in' => 300],
                        'raw' => '{}',
                    ];
                }
                if (str_contains($url, '/partner/runs/by-reference/') && !str_contains($url, '/callback')) {
                    return [
                        'statusCode' => 200,
                        'body' => ['id' => $this->runId, 'reference' => 'DEM-2026-00042'],
                        'raw' => '{}',
                    ];
                }

                return ['statusCode' => 201, 'body' => ['ok' => true], 'raw' => '{}'];
            }
        };
    }

    public function testSendMessageUsesReferenceThenPostsMessage(): void
    {
        $http = $this->httpStub();
        $client = $this->clientWithCapture($http);
        $out = $client->sendMessage(self::REFERENCE, 'Bonjour');

        $this->assertTrue($out['ok']);
        $this->assertCount(2, $http->calls);
        $this->assertSame('GET', $http->calls[0][0]);
        $this->assertStringContainsString('reference=DEM-2026-00042', $http->calls[0][1]);
        $this->assertSame('POST', $http->calls[1][0]);
        $this->assertStringContainsString('/partner/runs/' . self::RUN_ID . '/message/', $http->calls[1][1]);
        $this->assertSame('Bonjour', $http->calls[1][2]['body']);
    }

    public function testSendDocumentUsesReference(): void
    {
        $http = $this->httpStub();
        $client = $this->clientWithCapture($http);
        $client->sendDocument(
            self::REFERENCE,
            ResultData::make()->numero('REG-1')->documentUrl('https://x/a.pdf'),
            'Voici le document',
        );

        $this->assertStringContainsString('/document/', $http->calls[1][1]);
        $this->assertSame('REG-1', $http->calls[1][2]['data']['numero']);
        $this->assertSame('Voici le document', $http->calls[1][2]['message']);
    }

    public function testRequestDocumentsAndValidAppointmentUseReference(): void
    {
        $http = $this->httpStub();
        $client = $this->clientWithCapture($http);
        $client->requestDocuments(self::REFERENCE, ['CNI'], 'Merci');
        $client->validAppointment(self::REFERENCE, 'rdv', 'approve');

        // 2 lookups + 2 actions
        $this->assertCount(4, $http->calls);
        $this->assertStringContainsString('/request-documents/', $http->calls[1][1]);
        $this->assertSame(['CNI'], $http->calls[1][2]['items']);
        $this->assertStringContainsString('/validate-appointment/', $http->calls[3][1]);
        $this->assertSame('rdv', $http->calls[3][2]['step_code']);
        $this->assertSame('approve', $http->calls[3][2]['action']);
    }

    public function testPartnerCallsFetchOauthTokenThenSendBearer(): void
    {
        $http = $this->httpStub();
        $client = $this->clientWithCapture($http, [
            'oauthTokenUrl' => 'https://iam.example/realms/kong/protocol/openid-connect/token',
            'oauthClientId' => 'ande',
            'oauthClientSecret' => 'secret-oauth',
        ]);

        $client->callback(self::REFERENCE, CallbackRequest::completed(['x' => 1]));

        $this->assertGreaterThanOrEqual(2, count($http->calls));
        $this->assertSame('POST', $http->calls[0][0]);
        $this->assertStringContainsString('/openid-connect/token', $http->calls[0][1]);
        $this->assertSame('form', $http->calls[0][4]);
        $this->assertSame('client_credentials', $http->calls[0][2]['grant_type']);
        $this->assertSame('ande', $http->calls[0][2]['client_id']);

        $callbackCall = $http->calls[1];
        $this->assertStringContainsString('/partner/runs/by-reference/callback/', $callbackCall[1]);
        $this->assertSame('Bearer tok_test', $callbackCall[3]['Authorization'] ?? null);
        $this->assertSame('prt_test', $callbackCall[3]['X-Partner-Id'] ?? null);
        $this->assertSame('secret', $callbackCall[3]['X-Partner-Secret'] ?? null);
    }
}
