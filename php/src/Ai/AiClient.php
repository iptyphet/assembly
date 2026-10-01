<?php

declare(strict_types=1);

namespace Assembly\Ai;

use Assembly\Domain\Proposal;
use Assembly\Domain\ProposalVersion;

/**
 * AI-assisted amendment drafting. "AI proposes, humans dispose": the
 * client only ever produces a *patch suggestion* — a list of clause ops —
 * which becomes an amendment draft that humans hone, freeze, and vote on.
 */
interface AiClient
{
    /**
     * Suggest a patch for the proposal's latest version.
     *
     * @param list<array{clauseId: ?string, operation: string, text: ?string}>|null $previousDraftOps
     *        When set, this is a hone iteration: the model receives the
     *        previous patch plus the feedback in $ask.
     * @return array{ops: list<array{clauseId: ?string, operation: string, text: ?string}>, summary: string}
     * @throws PatchException When the provider fails or returns unusable output.
     */
    public function suggestPatch(
        Proposal $proposal,
        ProposalVersion $latestVersion,
        string $ask,
        ?array $previousDraftOps = null,
    ): array;
}
