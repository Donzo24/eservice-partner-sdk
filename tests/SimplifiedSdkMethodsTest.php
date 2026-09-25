<?php

declare(strict_types=1);

namespace EService\Partner\Tests;

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Exception\EServiceException;
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
                bool|string $verifySsl = true,
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
                if (str_contains($url, '/partner/runs/by-reference/message/')) {
                    return ['statusCode' => 201, 'body' => ['ok' => true], 'raw' => '{}'];
                }
                if (str_contains($url, '/partner/runs/by-reference/document/')) {
                    return ['statusCode' => 201, 'body' => ['ok' => true], 'raw' => '{}'];
                }
                if (str_contains($url, '/partner/runs/by-reference/complete/')) {
                    return [
                        'statusCode' => 200,
                        'body' => ['ok' => true, 'action' => 'completeDemand', 'phase' => 'done'],
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
        $this->assertCount(1, $http->calls);
        $this->assertSame('POST', $http->calls[0][0]);
        $this->assertStringContainsString('/partner/runs/by-reference/message/', $http->calls[0][1]);
        $this->assertSame(self::REFERENCE, $http->calls[0][2]['reference']);
        $this->assertSame('Bonjour', $http->calls[0][2]['body']);
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

        $this->assertCount(1, $http->calls);
        $this->assertStringContainsString('/by-reference/document/', $http->calls[0][1]);
        $this->assertSame(self::REFERENCE, $http->calls[0][2]['reference']);
        $this->assertSame('REG-1', $http->calls[0][2]['data']['numero']);
        $this->assertSame('Voici le document', $http->calls[0][2]['message']);
    }

    public function testSendDocumentUploadsAndKeepsFileMetadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eservice-doc-');
        self::assertNotFalse($path);
        file_put_contents($path, '%PDF-1.4 test');

        $http = $this->httpStub();
        $client = $this->clientWithCapture($http);
        $out = $client->sendDocument(
            self::REFERENCE,
            [
                'file' => $path,
                'filename' => 'decision.pdf',
                'numero' => 'REG-2',
                'title' => 'Décision',
            ],
            'Document définitif',
        );

        $this->assertTrue($out['ok']);
        $this->assertCount(1, $http->calls);
        $call = $http->calls[0];
        $this->assertStringContainsString('/by-reference/document/', $call[1]);
        $this->assertSame('multipart', $call[4]);
        $this->assertSame(self::REFERENCE, $call[2]['reference']);
        $this->assertInstanceOf(\CURLFile::class, $call[2]['file']);
        $this->assertSame('decision.pdf', $call[2]['file']->getPostFilename());
        $this->assertSame(
            ['numero' => 'REG-2', 'title' => 'Décision'],
            json_decode($call[2]['data'], true),
        );
        $this->assertArrayNotHasKey('Content-Type', $call[3]);

        @unlink($path);
    }

    public function testSendDocumentRejectsMissingLocalFile(): void
    {
        $client = $this->clientWithCapture($this->httpStub());

        $this->expectException(EServiceException::class);
        $client->sendDocument(self::REFERENCE, '/tmp/eservice-document-inexistant.pdf');
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

    public function testCompleteDemandUsesReference(): void
    {
        $http = $this->httpStub();
        $client = $this->clientWithCapture($http);
        $out = $client->completeDemand(self::REFERENCE, 'Dossier clôturé');

        $this->assertTrue($out['ok']);
        $this->assertSame('completeDemand', $out['action']);
        $this->assertSame('done', $out['phase']);
        $this->assertCount(1, $http->calls);
        $this->assertSame('POST', $http->calls[0][0]);
        $this->assertStringContainsString('/partner/runs/by-reference/complete/', $http->calls[0][1]);
        $this->assertSame(self::REFERENCE, $http->calls[0][2]['reference']);
        $this->assertSame('Dossier clôturé', $http->calls[0][2]['message']);
    }

    public function testSslVerifyOptionUsesCaBundlePath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ca');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        $config = Config::fromArray([
            'baseUrl' => 'https://api.example/v2',
            'verifySsl' => true,
            'caBundle' => $tmp,
        ]);
        $this->assertSame($tmp, $config->sslVerifyOption());

        $configOff = Config::fromArray([
            'verifySsl' => false,
            'caBundle' => $tmp,
        ]);
        $this->assertFalse($configOff->sslVerifyOption());

        @unlink($tmp);
    }
}
