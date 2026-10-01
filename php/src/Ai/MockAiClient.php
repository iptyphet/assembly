<?php

declare(strict_types=1);

namespace Assembly\Ai;

use Assembly\Domain\Amendment;
use Assembly\Domain\Proposal;
use Assembly\Domain\ProposalVersion;

/**
 * Deterministic canned patch so the full draft → hone → freeze → vote
 * flow works locally with zero API keys. Replaces the first clause with
 * text echoing a snippet of the ask; a hone iteration produces a visibly
 * different text (it echoes the *hone* ask and marks the revision).
 */
final class MockAiClient implements AiClient
{
    public function suggestPatch(
        Proposal $proposal,
        ProposalVersion $latestVersion,
        string $ask,
        ?array $previousDraftOps = null,
    ): array {
        $first = $latestVersion->clauses[0] ?? null;
        if ($first === null) {
            throw new PatchException('The proposal has no clauses to patch.');
        }
        $snippet = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $ask) ?? ''), 0, 60, '…');
        if ($snippet === '') {
            throw new PatchException('The ask is empty.');
        }
        $isHone = $previousDraftOps !== null;
        $text = $first->text . ' — amended per request: "' . $snippet . '"' . ($isHone ? ' (honed)' : '');

        return [
            'ops' => [[
                'clauseId' => $first->id,
                'operation' => Amendment::REPLACE,
                'text' => $text,
            ]],
            'summary' => ($isHone ? 'Mock hone' : 'Mock patch')
                . ': replaced clause 1 in response to "' . $snippet . '"',
        ];
    }
}
