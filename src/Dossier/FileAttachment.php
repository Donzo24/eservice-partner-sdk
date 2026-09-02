<?php

declare(strict_types=1);

namespace EService\Partner\Dossier;

/**
 * Fichier joint reçu dans un handoff eService (mode |file:content).
 *
 * Forme typique :
 * { "name": "piece.pdf", "contentType": "application/pdf", "contentBase64": "..." }
 */
final class FileAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly string $contentBase64,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            contentType: (string) ($data['contentType'] ?? $data['content_type'] ?? 'application/octet-stream'),
            contentBase64: (string) ($data['contentBase64'] ?? $data['content_base64'] ?? ''),
        );
    }

    public function binary(): string
    {
        $decoded = base64_decode($this->contentBase64, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('contentBase64 invalide pour le fichier « ' . $this->name . ' ».');
        }

        return $decoded;
    }

    /**
     * Enregistre le fichier sur disque. Retourne le chemin écrit.
     */
    public function saveTo(string $directory, ?string $filename = null): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire : ' . $directory);
        }
        $safeName = $filename ?? ($this->name !== '' ? basename($this->name) : 'attachment.bin');
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
        if (file_put_contents($path, $this->binary()) === false) {
            throw new \RuntimeException('Impossible d\'écrire le fichier : ' . $path);
        }

        return $path;
    }
}
