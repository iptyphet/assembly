<?php

declare(strict_types=1);

namespace Assembly\Ai;

use Assembly\Domain\Amendment;
use Assembly\Domain\ProposalVersion;

/**
 * The single validation point for patch ops, whether they come from the
 * mock, a real provider, or a hand-written form. Every op must reference
 * an existing clause (null clauseId is only allowed for insert-after at
 * the top), the operation must be one of the amendment kinds, and
 * replace/insert ops need text.
 */
final class PatchValidator
{
    /**
     * @param array<int, array<string, mixed>> $ops
     * @return list<array{clauseId: ?string, operation: string, text: ?string}>
     * @throws PatchException
     */
    public static function validate(array $ops, ProposalVersion $version): array
    {
        if ($ops === []) {
            throw new PatchException('The patch is empty — no clause operations.');
        }
        $clauseIds = array_map(static fn ($c): string => $c->id, $version->clauses);
        $result = [];
        foreach ($ops as $i => $op) {
            $n = $i + 1;
            if (!is_array($op)) {
                throw new PatchException("Op $n is not an object.");
            }
            $operation = (string) ($op['operation'] ?? '');
            if (!in_array($operation, Amendment::KINDS, true)) {
                throw new PatchException("Op $n has unknown operation \"$operation\".");
            }
            $clauseId = isset($op['clauseId']) && $op['clauseId'] !== '' ? (string) $op['clauseId'] : null;
            if ($clauseId === null && $operation !== Amendment::INSERT_AFTER) {
                throw new PatchException("Op $n does not say which clause it targets.");
            }
            if ($clauseId !== null && !in_array($clauseId, $clauseIds, true)) {
                throw new PatchException("Op $n targets clause \"$clauseId\", which does not exist in the latest version.");
            }
            $text = isset($op['text']) ? trim((string) $op['text']) : null;
            if ($operation !== Amendment::STRIKE && ($text === null || $text === '')) {
                throw new PatchException("Op $n needs replacement/insertion text.");
            }
            if ($operation === Amendment::STRIKE) {
                $text = null;
            }
            $result[] = ['clauseId' => $clauseId, 'operation' => $operation, 'text' => $text];
        }

        return $result;
    }
}
