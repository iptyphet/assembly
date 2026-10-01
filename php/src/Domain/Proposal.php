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
        public readonly string $createdByUserId,
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
            (string) ($data['createdByUserId'] ?? ''),
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
            'createdByUserId' => $this->createdByUserId,
            'createdByName' => $this->createdByName,
            'createdAtUtc' => $this->createdAtUtc,
            'note' => $this->note,
        ];
    }
}

/**
 * One iteration of an amendment's patch. Append-only: honing adds a new
 * revision, so the drafting history stays inspectable.
 */
final class AmendmentRevision
{
    /**
     * @param list<array{clauseId: ?string, operation: string, text: ?string}> $ops
     */
    public function __construct(
        public readonly string $ask,
        public readonly array $ops,
        public readonly string $summary,
        public readonly string $source,
        public readonly string $createdByUserId,
        public readonly string $createdAtUtc,
    ) {
    }

    public const AI = 'ai';
    public const HUMAN = 'human';

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['ask'] ?? ''),
            array_values((array) ($data['ops'] ?? [])),
            (string) ($data['summary'] ?? ''),
            in_array($data['source'] ?? null, [self::AI, self::HUMAN], true) ? (string) $data['source'] : self::HUMAN,
            (string) ($data['createdByUserId'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ask' => $this->ask,
            'ops' => $this->ops,
            'summary' => $this->summary,
            'source' => $this->source,
            'createdByUserId' => $this->createdByUserId,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }
}

final class Amendment
{
    public const REPLACE = 'replace_clause';
    public const STRIKE = 'strike_clause';
    public const INSERT_AFTER = 'insert_clause_after';

    public const KINDS = [self::REPLACE, self::STRIKE, self::INSERT_AFTER];

    public const DRAFT = 'draft';
    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /**
     * clauseId/kind/newText are the legacy single-op shape (pre-revision
     * documents). Revision-based amendments leave them null/empty and carry
     * their patch in $revisions; currentOps() bridges both.
     *
     * @param list<AmendmentRevision> $revisions
     */
    public function __construct(
        public readonly string $id,
        public readonly int $targetVersion,
        public readonly ?string $clauseId,
        public readonly string $kind,
        public readonly ?string $newText,
        public string $status,
        public readonly string $proposedByUserId,
        public readonly string $proposedByName,
        public readonly string $createdAtUtc,
        public array $revisions,
        public ?string $ballotId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (int) ($data['targetVersion'] ?? 1),
            isset($data['clauseId']) ? (string) $data['clauseId'] : null,
            (string) ($data['kind'] ?? ''),
            isset($data['newText']) ? (string) $data['newText'] : null,
            (string) ($data['status'] ?? self::PROPOSED),
            (string) ($data['proposedByUserId'] ?? ''),
            (string) ($data['proposedByName'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
            array_map(AmendmentRevision::fromArray(...), array_values((array) ($data['revisions'] ?? []))),
            isset($data['ballotId']) ? (string) $data['ballotId'] : null,
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
            'proposedByUserId' => $this->proposedByUserId,
            'proposedByName' => $this->proposedByName,
            'createdAtUtc' => $this->createdAtUtc,
            'revisions' => array_map(static fn (AmendmentRevision $r): array => $r->toArray(), $this->revisions),
            'ballotId' => $this->ballotId,
        ];
    }

    /**
     * The current patch: latest revision's ops, or the legacy single op
     * for pre-revision documents.
     *
     * @return list<array{clauseId: ?string, operation: string, text: ?string}>
     */
    public function currentOps(): array
    {
        if ($this->revisions !== []) {
            return $this->revisions[count($this->revisions) - 1]->ops;
        }
        if ($this->kind === '') {
            return [];
        }

        return [[
            'clauseId' => $this->clauseId,
            'operation' => $this->kind,
            'text' => $this->newText,
        ]];
    }

    /** May this user hone or freeze the draft? Proposer or chair/admin. */
    public function canHone(string $userId, bool $isChair): bool
    {
        return $this->status === self::DRAFT && ($this->proposedByUserId === $userId || $isChair);
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

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::PROPOSED => 'Proposed (frozen, voting)',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
            default => $status,
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
        public readonly string $createdByUserId,
        public readonly string $createdByName,
        public readonly string $createdAtUtc,
    ) {
    }

    /**
     * @param list<string> $clauseTexts
     */
    public static function create(string $title, array $clauseTexts, User $author): self
    {
        $clauses = array_map(
            static fn (string $text): Clause => new Clause(Store::newId(), $text),
            $clauseTexts,
        );

        return new self(
            Store::newId(),
            $title,
            self::DRAFT,
            [new ProposalVersion(1, $clauses, $author->id, $author->name, Store::nowUtc(), null)],
            [],
            $author->id,
            $author->name,
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
            (string) ($data['createdByUserId'] ?? ''),
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
            'createdByUserId' => $this->createdByUserId,
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

    /** @return list<Amendment> */
    public function draftAmendments(): array
    {
        return array_values(array_filter(
            $this->amendments,
            static fn (Amendment $a): bool => $a->status === Amendment::DRAFT,
        ));
    }

    /** Decided amendments (accepted/rejected) — the record behind the chain. */
    public function decidedAmendments(): array
    {
        return array_values(array_filter(
            $this->amendments,
            static fn (Amendment $a): bool => in_array($a->status, [Amendment::ACCEPTED, Amendment::REJECTED, Amendment::WITHDRAWN], true),
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
     * Apply a patch (list of clause ops) to a version's clauses and return
     * the resulting NEW clause list. Input clauses are never mutated.
     *
     * @param list<Clause> $clauses
     * @param list<array{clauseId: ?string, operation: string, text: ?string}> $ops
     * @return list<Clause>
     */
    public static function applyOps(array $clauses, array $ops): array
    {
        $result = $clauses;
        foreach ($ops as $op) {
            $clauseId = $op['clauseId'];
            $next = [];
            foreach ($result as $clause) {
                if ($clause->id === $clauseId && $op['operation'] === Amendment::STRIKE) {
                    continue;
                }
                if ($clause->id === $clauseId && $op['operation'] === Amendment::REPLACE) {
                    $next[] = new Clause(Store::newId(), (string) $op['text']);
                    continue;
                }
                $next[] = $clause;
                if ($clause->id === $clauseId && $op['operation'] === Amendment::INSERT_AFTER) {
                    $next[] = new Clause(Store::newId(), (string) $op['text']);
                }
            }
            // Insert-after with null clause id = insert at the top of the document.
            if ($clauseId === null && $op['operation'] === Amendment::INSERT_AFTER) {
                array_unshift($next, new Clause(Store::newId(), (string) $op['text']));
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * Apply an amendment's current patch to a version's clauses.
     *
     * @param list<Clause> $clauses
     * @return list<Clause>
     */
    public static function applyAmendment(array $clauses, Amendment $amendment): array
    {
        return self::applyOps($clauses, $amendment->currentOps());
    }

    /**
     * Accept an amendment: apply it to the latest version, append the new
     * immutable version, mark the amendment accepted.
     */
    public function acceptAmendment(Amendment $amendment, User $author): void
    {
        $latest = $this->latest();
        $clauses = self::applyAmendment($latest->clauses, $amendment);
        $this->versions[] = new ProposalVersion(
            $latest->number + 1,
            $clauses,
            $author->id,
            $author->name,
            Store::nowUtc(),
            'Accepted amendment by ' . $amendment->proposedByName,
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
