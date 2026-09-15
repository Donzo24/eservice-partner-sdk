<?php

declare(strict_types=1);

namespace EService\Partner\Tests;

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Dossier\FileAttachment;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Exception\AuthenticationException;
use EService\Partner\Http\HttpClientInterface;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackResponse;
use EService\Partner\Webhook\ResultData;
use PHPUnit\Framework\TestCase;

final class IncomingDossierTest extends TestCase
{
    public function testParsesCallbackUrlAndCitoyen(): void
    {
        $dossier = IncomingDossier::fromPayload([
            'dossierRef' => 'REF-001',
            'familyName' => 'Diallo',
            'givenName' => 'Aissatou',
            'email' => 'a@example.gn',
            'callbackUrl' => 'https://eservices.test/api/v1/workflows/portail/runs/550e8400-e29b-41d4-a716-446655440000/external-api/transfert-registre/callback/',
            'callbackToken' => 'tok-abc',
            'piece_fichier' => [
                'name' => 'cni.pdf',
                'contentType' => 'application/pdf',
                'contentBase64' => base64_encode('PDF'),
            ],
        ]);

        $this->assertSame('REF-001', $dossier->reference);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $dossier->runId);
        $this->assertSame('transfert-registre', $dossier->stepCode);
        $this->assertSame('Diallo', $dossier->citoyen['nom'] ?? null);
        $this->assertTrue($dossier->canCallback());

        $file = $dossier->file('piece_fichier');
        $this->assertInstanceOf(FileAttachment::class, $file);
        $this->assertSame('PDF', $file->binary());

        $ack = $dossier->ackResponse('JOB-1');
        $this->assertSame(['jobId' => 'JOB-1', 'status' => 'accepted'], $ack);
    }

    public function testCallbackRequestBody(): void
    {
        $body = CallbackRequest::completed(
            ['numero' => 'REG-1'],
            ['kind' => 'review', 'decision' => 'conforme', 'motif' => 'OK']
        )->toArray();

        $this->assertSame('completed', $body['status']);
        $this->assertSame('REG-1', $body['data']['numero']);
        $this->assertSame('conforme', $body['decision']);
        $this->assertSame('review', $body['kind']);
    }

    public function testClientPostsCallbackWithToken(): void
    {
        $http = new class implements HttpClientInterface {
            /** @var array<string, mixed>|null */
            public ?array $last = null;

            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?array $jsonBody = null,
                int $timeoutSeconds = 30,
                bool|string $verifySsl = true,
                string $bodyFormat = 'json',
            ): array {
                $this->last = compact('method', 'url', 'headers', 'jsonBody');

                return [
                    'statusCode' => 200,
                    'body' => [
                        'ok' => true,
                        'run_id' => '550e8400-e29b-41d4-a716-446655440000',
                        'step_code' => 'transfert-registre',
                        'status' => 'completed',
                        'transitioned' => true,
                        'externalData' => ['registre' => ['numero' => 'REG-1']],
                    ],
                    'raw' => '{}',
                ];
            }
        };

        $client = new Client(new Config(), $http);
        $dossier = IncomingDossier::fromPayload([
            'callbackUrl' => 'https://eservices.test/api/v1/workflows/portail/runs/550e8400-e29b-41d4-a716-446655440000/external-api/transfert-registre/callback/',
            'callbackToken' => 'secret-token',
        ]);

        $response = $client->review()->accept(
            $dossier,
            'OK',
            ResultData::make()->numero('REG-1'),
        );

        $this->assertInstanceOf(CallbackResponse::class, $response);
        $this->assertTrue($response->ok);
        $this->assertTrue($response->isCompleted());
        $this->assertSame('POST', $http->last['method']);
        $this->assertSame('secret-token', $http->last['headers']['X-Webhook-Token']);
        $this->assertSame('completed', $http->last['jsonBody']['status']);
        $this->assertSame('review', $http->last['jsonBody']['kind']);
        $this->assertSame('conforme', $http->last['jsonBody']['decision']);
        $this->assertSame('REG-1', $http->last['jsonBody']['data']['numero']);
    }

    public function testClientRequiresAuth(): void
    {
        $this->expectException(AuthenticationException::class);
        $client = new Client(new Config(baseUrl: 'https://eservices.test/api/v1'));
        $client->callback(
            'DEM-2026-00042',
            CallbackRequest::completed(),
        );
    }
}
