<?php

declare(strict_types=1);

namespace EService\Partner\Step;

/**
 * Instruction step kinds targeted by the partner SDK.
 *
 * Maps to eService workflow / agentBridge:
 * - review              → step_kind review
 * - approval            → step_kind approval
 * - signature           → step_kind document_signature
 * - document_generation → step_kind document_generation
 */
enum StepKind: string
{
    case Review = 'review';
    case Approval = 'approval';
    case Signature = 'signature';
    case DocumentGeneration = 'document_generation';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Review',
            self::Approval => 'Approval',
            self::Signature => 'Signature',
            self::DocumentGeneration => 'Document generation',
        };
    }

    /** agentBridge ``kind`` (review / approval only). */
    public function agentBridgeKind(): ?string
    {
        return match ($this) {
            self::Review => 'review',
            self::Approval => 'approval',
            default => null,
        };
    }

    public function workflowStepKind(): string
    {
        return match ($this) {
            self::Review => 'review',
            self::Approval => 'approval',
            self::Signature => 'document_signature',
            self::DocumentGeneration => 'document_generation',
        };
    }

    public static function tryFromPayload(mixed $value): ?self
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $token = mb_strtolower(trim($value));

        return match ($token) {
            'review', 'controle', 'contrôle', 'control' => self::Review,
            'approval', 'approbation', 'decision', 'décision', 'approve' => self::Approval,
            'signature', 'document_signature', 'sign' => self::Signature,
            'document_generation', 'generation_document', 'document', 'generation', 'génération' => self::DocumentGeneration,
            default => self::tryFrom($token),
        };
    }
}
