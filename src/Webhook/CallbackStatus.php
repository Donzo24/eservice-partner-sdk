<?php

declare(strict_types=1);

namespace EService\Partner\Webhook;

/**
 * Valeurs de ``status`` du callback partenaire → eService.
 *
 * Aligné sur les défauts workflow (``async.completion``) :
 * - completedValues : ``completed``, ``done``
 * - failedValues    : ``failed``, ``rejected``
 *
 * Toute autre valeur (ex. ``processing``) laisse le dossier en ``awaiting_external``.
 *
 * @see frontend/src/views/workflows/externalApiDefinition.js
 * @see backend/workflow/external_api_async.py::classify_completion_status
 */
enum CallbackStatus: string
{
    /** Traitement terminé avec succès (completedValues). */
    case Completed = 'completed';

    /** Alias succès accepté par défaut côté eService (completedValues). */
    case Done = 'done';

    /** Échec technique / métier (failedValues). */
    case Failed = 'failed';

    /** Rejet explicite (failedValues). */
    case Rejected = 'rejected';

    /**
     * Progression intermédiaire — hors completed/failedValues,
     * le backend conserve ``awaiting_external``.
     */
    case Processing = 'processing';

    public function isSuccess(): bool
    {
        return $this === self::Completed || $this === self::Done;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Rejected;
    }

    public function isTerminal(): bool
    {
        return $this->isSuccess() || $this->isFailure();
    }
}
