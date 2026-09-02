<?php

declare(strict_types=1);

namespace EService\Partner\Step;

/**
 * Approval decision codes (``decision`` in the webhook body).
 * Values match typical eService agentBridge approval outcomes.
 */
enum ApprovalDecision: string
{
    case Approved = 'approuve';
    case Rejected = 'rejete';
    case Favorable = 'favorable';
    case Rejection = 'rejet';
    case Remand = 'renvoi';
}
