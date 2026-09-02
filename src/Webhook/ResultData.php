<?php

declare(strict_types=1);

namespace EService\Partner\Webhook;

/**
 * Données métier du callback (objet ``data``).
 *
 * Les clés JSON sont fixées par le SDK — pas de saisie manuelle des noms de champs.
 *
 * Exemple :
 * ``ResultData::make()->numero('REG-2026-001')->documentUrl('https://…')``
 */
final class ResultData
{
    /** @var array<string, mixed> */
    private array $fields = [];

    public static function make(): self
    {
        return new self();
    }

    public function numero(string $value): self
    {
        return $this->put('numero', $value);
    }

    public function documentUrl(string $value): self
    {
        return $this->put('documentUrl', $value);
    }

    public function filename(string $value): self
    {
        return $this->put('filename', $value);
    }

    public function title(string $value): self
    {
        return $this->put('title', $value);
    }

    public function contentType(string $value): self
    {
        return $this->put('contentType', $value);
    }

    public function contentBase64(string $value): self
    {
        return $this->put('contentBase64', $value);
    }

    public function generatedAt(string $value): self
    {
        return $this->put('generatedAt', $value);
    }

    public function signedAt(string $value): self
    {
        return $this->put('signedAt', $value);
    }

    public function signatoryId(string $value): self
    {
        return $this->put('signatoryId', $value);
    }

    public function signatoryName(string $value): self
    {
        return $this->put('signatoryName', $value);
    }

    /**
     * Champ additionnel mappé côté workflow (async.resultMappings).
     * À réserver aux clés hors catalogue standard.
     */
    public function custom(string $key, mixed $value): self
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Clé ResultData vide.');
        }

        return $this->put($key, $value);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    private function put(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->fields[$key] = $value;

        return $clone;
    }
}
