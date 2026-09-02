<?php

declare(strict_types=1);

namespace EService\Partner\Exception;

final class ApiException extends EServiceException
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getDetail(): ?string
    {
        if ($this->responseBody === null) {
            return null;
        }
        $detail = $this->responseBody['detail'] ?? null;

        return is_string($detail) ? $detail : null;
    }
}
