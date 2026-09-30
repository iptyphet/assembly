<?php

declare(strict_types=1);

namespace Assembly\Domain;

use Assembly\Data\Store;

/**
 * Proposal aggregate: an immutable ProposalVersion chain (text as a list of
 * clauses) plus an Amendment list. Accepting an amendment applies it to the
 * latest version and appends a new version; nothing is ever edited in place.
 * Mirrors dotnet/src/Core/Domain/Proposal.cs and Amendment.cs.
 */
final class Clause
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['id'], (string) $data['text']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text];
    }
}

final class ProposalVersion
{
    /**
     * @param list<Clause> $clauses
     */
    public function __construct(
        public readonly int $number,
        public readonly array $clauses,
        public readonly string $createdByName,
        public readonly string $createdAtUtc,
        public readonly ?string $note,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['number'],
            array_map(Clause::fromArray(...), array_values((array) $data['clauses'])),
            (string) ($data['createdByName'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
            isset($data['note']) ? (string) $data['note'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'clauses' => array_map(static fn (Clause $c): array => $c->toArray(), $this->clauses),
            'createdByName' => $this->createdByName,
            'createdAtUtc' => $this->createdAtUtc,
            'note' => $this->note,
        ];
    }
}

final class Amendment
{
    public const REPLACE = 'replace_clause';
    public const STRIKE = 'strike_clause';
    public const INSERT_AFTER = 'insert_clause_after';

    public const KINDS = [self::REPLACE, self::STRIKE, self::INSERT_AFTER];

    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly string $id,
        public readonly int $targetVersion,
        public readonly ?string $clauseId,
        public readonly string $kind,
        public readonly ?string $newText,
        public string $status,
        public readonly string $proposedByName,
        public readonly string $createdAtUtc,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (int) ($data['targetVersion'] ?? 1),
            isset($data['clauseId']) ? (string) $data['clauseId'] : null,
            (string) $data['kind'],
            isset($data['newText']) ? (string) $data['newText'] : null,
            (string) ($data['status'] ?? self::PROPOSED),
            (string) ($data['proposedByName'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'targetVersion' => $this->targetVersion,
            'clauseId' => $this->clauseId,
            'kind' => $this->kind,
            'newText' => $this->newText,
            'status' => $this->status,
            'proposedByName' => $this->proposedByName,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::REPLACE => 'Replace clause',
            self::STRIKE => 'Strike clause',
            self::INSERT_AFTER => 'Insert clause after',
            default => $kind,
        };
    }
}

final class Proposal
{
    public const DRAFT = 'draft';
    public const IN_WORKING_GROUP = 'in_working_group';
    public const READY_FOR_SESSION = 'ready_for_session';
    public const ADOPTED = 'adopted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::DRAFT,
        self::IN_WORKING_GROUP,
        self::READY_FOR_SESSION,
        self::ADOPTED,
        self::REJECTED,
        self::WITHDRAWN,
    ];

    /**
     * @param list<ProposalVersion> $versions
     * @param list<Amendment> $amendments
     */
    public function __construct(
        public readonly string $id,
        public string $title,
        public string $status,
        public array $versions,
        public array $amendments,
        public readonly string $createdByName,
        public readonly string $createdAtUtc,
    ) {
    }

    /**
     * @param list<string> $clauseTexts
     */
    public static function create(string $title, array $clauseTexts, string $authorName): self
    {
        $clauses = array_map(
            static fn (string $text): Clause => new Clause(Store::newId(), $text),
            $clauseTexts,
        );

        return new self(
            Store::newId(),
            $title,
            self::DRAFT,
            [new ProposalVersion(1, $clauses, $authorName, Store::nowUtc(), null)],
            [],
            $authorName,
            Store::nowUtc(),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['title'],
            (string) ($data['status'] ?? self::DRAFT),
            array_map(ProposalVersion::fromArray(...), array_values((array) $data['versions'])),
            array_map(Amendment::fromArray(...), array_values((array) ($data['amendments'] ?? []))),
            (string) ($data['createdByName'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'versions' => array_map(static fn (ProposalVersion $v): array => $v->toArray(), $this->versions),
            'amendments' => array_map(static fn (Amendment $a): array => $a->toArray(), $this->amendments),
            'createdByName' => $this->createdByName,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    public function latest(): ProposalVersion
    {
        return $this->versions[count($this->versions) - 1];
    }

    public function version(int $number): ?ProposalVersion
    {
        foreach ($this->versions as $version) {
            if ($version->number === $number) {
                return $version;
            }
        }

        return null;
    }

    /** @return list<Amendment> */
    public function pendingAmendments(): array
    {
        return array_values(array_filter(
            $this->amendments,
            static fn (Amendment $a): bool => $a->status === Amendment::PROPOSED,
        ));
    }

    public function amendment(string $id): ?Amendment
    {
        foreach ($this->amendments as $amendment) {
            if ($amendment->id === $id) {
                return $amendment;
            }
        }

        return null;
    }

    /**
     * Apply an amendment to a version's clauses and return the resulting NEW
     * clause list. Input clauses are never mutated.
     *
     * @param list<Clause> $clauses
     * @return list<Clause>
     */
    public static function applyAmendment(array $clauses, Amendment $amendment): array
    {
        $result = [];
        foreach ($clauses as $clause) {
            if ($clause->id === $amendment->clauseId && $amendment->kind === Amendment::STRIKE) {
                continue;
            }
            if ($clause->id === $amendment->clauseId && $amendment->kind === Amendment::REPLACE) {
                $result[] = new Clause(Store::newId(), (string) $amendment->newText);
                continue;
            }
            $result[] = $clause;
            if ($clause->id === $amendment->clauseId && $amendment->kind === Amendment::INSERT_AFTER) {
                $result[] = new Clause(Store::newId(), (string) $amendment->newText);
            }
        }
        // Insert-after with null clause id = insert at the top of the document.
        if ($amendment->clauseId === null && $amendment->kind === Amendment::INSERT_AFTER) {
            array_unshift($result, new Clause(Store::newId(), (string) $amendment->newText));
        }

        return $result;
    }

    /**
     * Accept an amendment: apply it to the latest version, append the new
     * immutable version, mark the amendment accepted.
     */
    public function acceptAmendment(Amendment $amendment, string $authorName): void
    {
        $latest = $this->latest();
        $clauses = self::applyAmendment($latest->clauses, $amendment);
        $this->versions[] = new ProposalVersion(
            $latest->number + 1,
            $clauses,
            $authorName,
            Store::nowUtc(),
            'Accepted ' . Amendment::kindLabel($amendment->kind) . ' by ' . $amendment->proposedByName,
        );
        $amendment->status = Amendment::ACCEPTED;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::IN_WORKING_GROUP => 'In working group',
            self::READY_FOR_SESSION => 'Ready for session',
            self::ADOPTED => 'Adopted',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
            default => $status,
        };
    }
}
