<?php

declare(strict_types=1);

namespace EService\Partner\Tests;

use EService\Partner\Client;
use EService\Partner\Config;
use EService\Partner\Dossier\IncomingDossier;
use EService\Partner\Http\HttpClientInterface;
use EService\Partner\Step\StepKind;
use EService\Partner\Webhook\CallbackRequest;
use EService\Partner\Webhook\CallbackStatus;
use EService\Partner\Webhook\ResultData;
use PHPUnit\Framework\TestCase;

final class InstructionStepsTest extends TestCase
{
    public function testCallbackStatusMatchesBackendDefaults(): void
    {
        $this->assertSame(['completed', 'done'], [
            CallbackStatus::Completed->value,
            CallbackStatus::Done->value,
        ]);
        $this->assertSame(['failed', 'rejected'], [
            CallbackStatus::Failed->value,
            CallbackStatus::Rejected->value,
        ]);
        $this->assertTrue(CallbackStatus::Completed->isSuccess());
        $this->assertTrue(CallbackStatus::Rejected->isFailure());
        $this->assertFalse(CallbackStatus::Processing->isTerminal());
    }

    public function testParsesInstructionStep(): void
    {
        $dossier = IncomingDossier::fromPayload([
            'instructionStep' => 'review',
            'callbackUrl' => 'https://x/api/v1/workflows/portail/runs/550e8400-e29b-41d4-a716-446655440000/external-api/transfert/callback/',
            'callbackToken' => 'tok',
        ]);
        $this->assertSame(StepKind::Review, $dossier->instructionStep);
    }

    public function testResultDataBuildsCanonicalPayload(): void
    {
        $expected = [
            'status' => CallbackStatus::Completed->value,
            'kind' => 'review',
            'decision' => 'conforme',
            'motif' => 'Dossier instruit et validé',
            'data' => [
                'numero' => 'REG-2026-001',
                'documentUrl' => 'https://partenaire.example/docs/REG-2026-001.pdf',
            ],
        ];

        $data = ResultData::make()
            ->numero('REG-2026-001')
            ->documentUrl('https://partenaire.example/docs/REG-2026-001.pdf');

        $request = CallbackRequest::review(
            'conforme',
            'Dossier instruit et validé',
            $data->toArray(),
        );

        $this->assertSame(CallbackStatus::Completed, $request->status);
        $this->assertSame($expected, $request->toArray());
    }

    public function testReviewApiDoesNotRequireManualKeys(): void
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
                $this->last = $jsonBody;

                return [
                    'statusCode' => 200,
                    'body' => [
                        'ok' => true,
                        'run_id' => '550e8400-e29b-41d4-a716-446655440000',
                        'step_code' => 'transfert',
                        'status' => CallbackStatus::Completed->value,
                        'transitioned' => true,
                    ],
                    'raw' => '{}',
                ];
            }
        };

        $client = new Client(new Config(), $http);
        $dossier = IncomingDossier::fromPayload([
            'callbackUrl' => 'https://x/api/v1/workflows/portail/runs/550e8400-e29b-41d4-a716-446655440000/external-api/transfert/callback/',
            'callbackToken' => 'tok',
        ]);

        $client->review()->accept(
            $dossier,
            'Dossier instruit et validé',
            ResultData::make()
                ->numero('REG-2026-001')
                ->documentUrl('https://partenaire.example/docs/REG-2026-001.pdf'),
        );

        $this->assertSame(CallbackStatus::Completed->value, $http->last['status']);
        $this->assertSame('review', $http->last['kind']);
        $this->assertSame('conforme', $http->last['decision']);
        $this->assertSame('REG-2026-001', $http->last['data']['numero']);
    }
}
