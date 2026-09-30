<?php

declare(strict_types=1);

namespace Assembly\Domain;

use Assembly\Data\Store;

/**
 * A topic groups related sessions (e.g. "Membership and fees").
 */
final class Topic
{
    public function __construct(
        public readonly string $id,
        public string $title,
        public string $description,
        public readonly string $createdByUserId,
        public readonly string $createdAtUtc,
    ) {
    }

    public static function create(string $title, string $description, string $userId): self
    {
        return new self(Store::newId(), $title, $description, $userId, Store::nowUtc());
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['title'],
            (string) ($data['description'] ?? ''),
            (string) ($data['createdByUserId'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'createdByUserId' => $this->createdByUserId,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }
}
