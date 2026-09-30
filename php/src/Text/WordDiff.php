<?php

declare(strict_types=1);

namespace Assembly\Text;

/**
 * Word-level diff (longest common subsequence) used to render amendments
 * with strike-through for removed text and highlight for inserted text.
 * Straight port of the .NET lane's Core/Text/WordDiff.cs.
 */
final class WordDiff
{
    public const SAME = 'same';
    public const REMOVED = 'removed';
    public const ADDED = 'added';

    /**
     * @return list<array{kind: string, text: string}>
     */
    public static function compute(string $oldText, string $newText): array
    {
        $oldWords = self::tokenize($oldText);
        $newWords = self::tokenize($newText);

        $lcs = self::lcsTable($oldWords, $newWords);
        $segments = [];
        self::backtrack($lcs, $oldWords, $newWords, count($oldWords), count($newWords), $segments);

        return self::coalesce($segments);
    }

    /** @return list<string> */
    private static function tokenize(string $text): array
    {
        return array_values(array_filter(explode(' ', $text), static fn (string $w): bool => $w !== ''));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return array<int, array<int, int>>
     */
    private static function lcsTable(array $a, array $b): array
    {
        $table = array_fill(0, count($a) + 1, array_fill(0, count($b) + 1, 0));

        for ($i = 1; $i <= count($a); $i++) {
            for ($j = 1; $j <= count($b); $j++) {
                $table[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $table[$i - 1][$j - 1] + 1
                    : max($table[$i][$j - 1], $table[$i - 1][$j]);
            }
        }

        return $table;
    }

    /**
     * @param array<int, array<int, int>> $table
     * @param list<string> $a
     * @param list<string> $b
     * @param list<array{kind: string, text: string}> $output
     */
    private static function backtrack(array $table, array $a, array $b, int $i, int $j, array &$output): void
    {
        if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
            self::backtrack($table, $a, $b, $i - 1, $j - 1, $output);
            $output[] = ['kind' => self::SAME, 'text' => $a[$i - 1]];
        } elseif ($j > 0 && ($i === 0 || $table[$i][$j - 1] >= $table[$i - 1][$j])) {
            self::backtrack($table, $a, $b, $i, $j - 1, $output);
            $output[] = ['kind' => self::ADDED, 'text' => $b[$j - 1]];
        } elseif ($i > 0) {
            self::backtrack($table, $a, $b, $i - 1, $j, $output);
            $output[] = ['kind' => self::REMOVED, 'text' => $a[$i - 1]];
        }
    }

    /**
     * @param list<array{kind: string, text: string}> $segments
     * @return list<array{kind: string, text: string}>
     */
    private static function coalesce(array $segments): array
    {
        $result = [];

        foreach ($segments as $segment) {
            $last = count($result) - 1;
            if ($result !== [] && $result[$last]['kind'] === $segment['kind']) {
                $result[$last]['text'] .= ' ' . $segment['text'];
            } else {
                $result[] = $segment;
            }
        }

        return $result;
    }
}
