<?php

declare(strict_types=1);

namespace EService\Partner\Step;

/**
 * Review decision codes (``decision`` in the webhook body).
 * Values match typical eService agentBridge review outcomes.
 */
enum ReviewDecision: string
{
    case Compliant = 'conforme';
    case Rejected = 'refuse';
    case Complement = 'complement';
    case Admissible = 'recevable';
    case Inadmissible = 'irrecevable';
}
