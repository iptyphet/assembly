<?php

declare(strict_types=1);

namespace Assembly\Domain;

use Assembly\Data\Store;

/**
 * An open ballot on an agenda item's linked proposal. Open = roll call:
 * the tally shows who voted what. Secret ballots remain out of scope for
 * the sandbox. Choices follow the ternary model of the .NET lane.
 *
 * Votes are individual documents keyed votes/{ballotId}/{userId}.json, so
 * one vote per user per ballot holds by key construction and re-voting is
 * an idempotent overwrite. Tallies are recomputed from the vote documents
 * on every read — no counters to drift.
 */
final class Ballot
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public const FOR = 'for';
    public const AGAINST = 'against';
    public const ABSTAIN = 'abstain';

    public const CHOICES = [self::FOR, self::AGAINST, self::ABSTAIN];

    public function __construct(
        public readonly string $id,
        public readonly string $sessionId,
        public readonly string $agendaItemId,
        public readonly string $proposalId,
        public string $title,
        public string $status,
        public readonly string $createdByUserId,
        public readonly string $createdAtUtc,
        public ?string $closedAtUtc,
        public ?string $amendmentId,
    ) {
    }

    public static function create(
        string $sessionId,
        string $agendaItemId,
        string $proposalId,
        string $title,
        string $userId,
        ?string $amendmentId = null,
    ): self {
        return new self(
            Store::newId(),
            $sessionId,
            $agendaItemId,
            $proposalId,
            $title,
            self::OPEN,
            $userId,
            Store::nowUtc(),
            null,
            $amendmentId,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['sessionId'] ?? ''),
            (string) ($data['agendaItemId'] ?? ''),
            (string) ($data['proposalId'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['status'] ?? self::OPEN) === self::CLOSED ? self::CLOSED : self::OPEN,
            (string) ($data['createdByUserId'] ?? ''),
            (string) ($data['createdAtUtc'] ?? ''),
            isset($data['closedAtUtc']) ? (string) $data['closedAtUtc'] : null,
            isset($data['amendmentId']) ? (string) $data['amendmentId'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->sessionId,
            'agendaItemId' => $this->agendaItemId,
            'proposalId' => $this->proposalId,
            'title' => $this->title,
            'status' => $this->status,
            'createdByUserId' => $this->createdByUserId,
            'createdAtUtc' => $this->createdAtUtc,
            'closedAtUtc' => $this->closedAtUtc,
            'amendmentId' => $this->amendmentId,
        ];
    }

    /** A new vote document; the document key (userId) enforces uniqueness. */
    public static function vote(string $ballotId, User $user, string $choice): array
    {
        return [
            'id' => $user->id,
            'ballotId' => $ballotId,
            'userId' => $user->id,
            'userName' => $user->name,
            'choice' => $choice,
            'castAtUtc' => Store::nowUtc(),
        ];
    }

    /**
     * Recompute the tally from vote documents.
     *
     * @param array<string, array<string, mixed>> $votes keyed by user id
     * @return array{for: int, against: int, abstain: int}
     */
    public static function tally(array $votes): array
    {
        $tally = [self::FOR => 0, self::AGAINST => 0, self::ABSTAIN => 0];
        foreach ($votes as $vote) {
            $choice = (string) ($vote['choice'] ?? '');
            if (isset($tally[$choice])) {
                $tally[$choice]++;
            }
        }

        return $tally;
    }
}
