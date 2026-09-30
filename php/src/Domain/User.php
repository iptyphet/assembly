<?php

declare(strict_types=1);

namespace Assembly\Domain;

use Assembly\Data\Store;

/**
 * A sandbox user. Roles mirror the .NET lane's Admin/Chair vocabulary, plus
 * Attendant for regular participants. There is no password or real auth —
 * users are impersonated via the header dropdown.
 */
final class User
{
    public const ADMIN = 'admin';
    public const CHAIR = 'chair';
    public const ATTENDANT = 'attendant';

    public const ROLES = [self::ADMIN, self::CHAIR, self::ATTENDANT];

    public function __construct(
        public readonly string $id,
        public string $name,
        public string $role,
        public readonly string $createdAtUtc,
    ) {
    }

    public static function create(string $name, string $role): self
    {
        return new self(Store::newId(), $name, $role, Store::nowUtc());
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['name'],
            in_array($data['role'] ?? null, self::ROLES, true) ? (string) $data['role'] : self::ATTENDANT,
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ADMIN => 'Admin',
            self::CHAIR => 'Chair',
            self::ATTENDANT => 'Attendant',
            default => $role,
        };
    }
}
