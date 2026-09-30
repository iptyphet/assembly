<?php

declare(strict_types=1);

namespace Assembly\Domain;

use Assembly\Data\Store;

/**
 * Session aggregate: agenda items, each with a speakers list. Replies
 * (replikk) jump the queue ahead of speeches (innlegg); otherwise FIFO —
 * unless the chair has rearranged the queue manually (manualOrder).
 * Mirrors dotnet/src/Core/Domain/Session.cs.
 */
final class SpeakerEntry
{
    public const SPEECH = 'speech';
    public const REPLY = 'reply';

    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $displayName,
        public readonly string $kind,
        public readonly string $requestedAtUtc,
        public bool $done,
        public ?int $minutes,
        public ?int $manualOrder,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['userId'] ?? ''),
            (string) ($data['displayName'] ?? ''),
            (string) ($data['kind'] ?? self::SPEECH),
            (string) ($data['requestedAtUtc'] ?? ''),
            (bool) ($data['done'] ?? false),
            isset($data['minutes']) ? (int) $data['minutes'] : null,
            isset($data['manualOrder']) ? (int) $data['manualOrder'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'displayName' => $this->displayName,
            'kind' => $this->kind,
            'requestedAtUtc' => $this->requestedAtUtc,
            'done' => $this->done,
            'minutes' => $this->minutes,
            'manualOrder' => $this->manualOrder,
        ];
    }
}

final class AgendaItem
{
    /**
     * @param list<SpeakerEntry> $speakers
     */
    public function __construct(
        public readonly string $id,
        public string $title,
        public ?string $proposalId,
        public array $speakers,
        public ?string $nowSpeakingEntryId,
    ) {
    }

    public static function create(string $title, ?string $proposalId): self
    {
        return new self(Store::newId(), $title, $proposalId, [], null);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['title'],
            isset($data['proposalId']) ? (string) $data['proposalId'] : null,
            array_map(SpeakerEntry::fromArray(...), array_values((array) ($data['speakers'] ?? []))),
            isset($data['nowSpeakingEntryId']) ? (string) $data['nowSpeakingEntryId'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'proposalId' => $this->proposalId,
            'speakers' => array_map(static fn (SpeakerEntry $s): array => $s->toArray(), $this->speakers),
            'nowSpeakingEntryId' => $this->nowSpeakingEntryId,
        ];
    }

    public function speaker(string $id): ?SpeakerEntry
    {
        foreach ($this->speakers as $speaker) {
            if ($speaker->id === $id) {
                return $speaker;
            }
        }

        return null;
    }

    public function nowSpeaking(): ?SpeakerEntry
    {
        return $this->nowSpeakingEntryId === null ? null : $this->speaker($this->nowSpeakingEntryId);
    }

    /**
     * Pending queue in floor order. Default: replies jump ahead of speeches
     * (Nordic innlegg/replikk convention), otherwise first come first serve.
     * Once the chair rearranges (manualOrder set on any entry), manual
     * order wins; entries without one (applied later) sort after, by the
     * default rule.
     *
     * @return list<SpeakerEntry>
     */
    public function queue(): array
    {
        $pending = array_values(array_filter(
            $this->speakers,
            fn (SpeakerEntry $s): bool => !$s->done && $s->id !== $this->nowSpeakingEntryId,
        ));
        $hasManual = array_filter($pending, static fn (SpeakerEntry $s): bool => $s->manualOrder !== null) !== [];
        usort($pending, static function (SpeakerEntry $a, SpeakerEntry $b) use ($hasManual): int {
            if ($hasManual) {
                $orderA = $a->manualOrder ?? PHP_INT_MAX;
                $orderB = $b->manualOrder ?? PHP_INT_MAX;
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }
            }
            $rankA = $a->kind === SpeakerEntry::REPLY ? 0 : 1;
            $rankB = $b->kind === SpeakerEntry::REPLY ? 0 : 1;

            return $rankA <=> $rankB ?: strcmp($a->requestedAtUtc, $b->requestedAtUtc);
        });

        return $pending;
    }

    /** Stamp the current default queue order onto every entry. */
    public function snapshotQueueOrder(): void
    {
        foreach ($this->queue() as $i => $speaker) {
            $speaker->manualOrder = $i;
        }
    }

    /** Total minutes the chair has allocated on this item's queue. */
    public function allocatedMinutes(): int
    {
        $total = 0;
        foreach ($this->queue() as $speaker) {
            $total += $speaker->minutes ?? 0;
        }

        return $total;
    }
}

final class Session
{
    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const CLOSED = 'closed';

    public const STATUSES = [self::SCHEDULED, self::LIVE, self::CLOSED];

    /**
     * @param list<AgendaItem> $agendaItems
     */
    public function __construct(
        public readonly string $id,
        public string $title,
        public ?string $scheduledForUtc,
        public ?string $topicId,
        public ?string $ownerUserId,
        public ?string $originalProposalId,
        public string $status,
        public array $agendaItems,
        public ?string $currentAgendaItemId,
        public readonly string $createdAtUtc,
    ) {
    }

    public static function create(string $title, ?string $scheduledForUtc, ?string $topicId, string $ownerUserId): self
    {
        return new self(
            Store::newId(),
            $title,
            $scheduledForUtc,
            $topicId,
            $ownerUserId,
            null,
            self::SCHEDULED,
            [],
            null,
            Store::nowUtc(),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = (string) ($data['status'] ?? self::SCHEDULED);

        return new self(
            (string) $data['id'],
            (string) $data['title'],
            isset($data['scheduledForUtc']) ? (string) $data['scheduledForUtc'] : null,
            isset($data['topicId']) ? (string) $data['topicId'] : null,
            isset($data['ownerUserId']) ? (string) $data['ownerUserId'] : null,
            isset($data['originalProposalId']) ? (string) $data['originalProposalId'] : null,
            in_array($status, self::STATUSES, true) ? $status : self::SCHEDULED,
            array_map(AgendaItem::fromArray(...), array_values((array) ($data['agendaItems'] ?? []))),
            isset($data['currentAgendaItemId']) ? (string) $data['currentAgendaItemId'] : null,
            (string) ($data['createdAtUtc'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'scheduledForUtc' => $this->scheduledForUtc,
            'topicId' => $this->topicId,
            'ownerUserId' => $this->ownerUserId,
            'originalProposalId' => $this->originalProposalId,
            'status' => $this->status,
            'agendaItems' => array_map(static fn (AgendaItem $a): array => $a->toArray(), $this->agendaItems),
            'currentAgendaItemId' => $this->currentAgendaItemId,
            'createdAtUtc' => $this->createdAtUtc,
        ];
    }

    public function agendaItem(string $id): ?AgendaItem
    {
        foreach ($this->agendaItems as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::SCHEDULED => 'Scheduled',
            self::LIVE => 'Live',
            self::CLOSED => 'Closed',
            default => $status,
        };
    }
}
