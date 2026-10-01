<?php

declare(strict_types=1);

namespace Assembly\Ai;

use Assembly\Domain\Amendment;
use Assembly\Domain\Proposal;
use Assembly\Domain\ProposalVersion;

/**
 * Anthropic Messages API client. Structured output via forced tool use:
 * the model must call the `propose_patch` tool whose input schema matches
 * the ops shape exactly, so the response is parseable by construction.
 * Plain curl, no SDK.
 */
final class AnthropicAiClient implements AiClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function suggestPatch(
        Proposal $proposal,
        ProposalVersion $latestVersion,
        string $ask,
        ?array $previousDraftOps = null,
    ): array {
        if ($this->apiKey === '') {
            throw new PatchException('Anthropic provider selected but no api_key configured.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'tools' => [[
                'name' => 'propose_patch',
                'description' => 'Propose a patch to the proposal as a list of clause operations.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ops' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'clauseId' => ['type' => ['string', 'null']],
                                    'operation' => [
                                        'type' => 'string',
                                        'enum' => Amendment::KINDS,
                                    ],
                                    'text' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['clauseId', 'operation', 'text'],
                            ],
                        ],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['ops', 'summary'],
                ],
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'propose_patch'],
            'messages' => [[
                'role' => 'user',
                'content' => $this->prompt($proposal, $latestVersion, $ask, $previousDraftOps),
            ]],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new PatchException('Anthropic request failed: ' . ($error !== '' ? $error : 'empty response'));
        }
        $response = json_decode((string) $body, true);
        if (!is_array($response)) {
            throw new PatchException('Anthropic returned non-JSON output.');
        }
        if ($status !== 200) {
            $message = $response['error']['message'] ?? "HTTP $status";
            throw new PatchException("Anthropic error: $message");
        }

        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'propose_patch') {
                $input = $block['input'] ?? null;
                if (!is_array($input) || !isset($input['ops']) || !is_array($input['ops'])) {
                    throw new PatchException('Anthropic tool output has no ops.');
                }

                return [
                    'ops' => array_values($input['ops']),
                    'summary' => (string) ($input['summary'] ?? 'AI patch'),
                ];
            }
        }

        throw new PatchException('Anthropic response contained no propose_patch tool call.');
    }

    /** @param list<array{clauseId: ?string, operation: string, text: ?string}>|null $previousDraftOps */
    private function prompt(
        Proposal $proposal,
        ProposalVersion $latestVersion,
        string $ask,
        ?array $previousDraftOps,
    ): string {
        $lines = [
            'You are helping draft an amendment to a deliberative-assembly proposal.',
            'The proposal is titled "' . $proposal->title . '" and its latest version has these clauses:',
        ];
        foreach ($latestVersion->clauses as $i => $clause) {
            $lines[] = sprintf('§%d (clauseId "%s"): %s', $i + 1, $clause->id, $clause->text);
        }
        $lines[] = '';
        if ($previousDraftOps !== null) {
            $lines[] = 'A previous patch draft was: ' . json_encode($previousDraftOps);
            $lines[] = 'The proposer was not happy and asks: "' . $ask . '"';
            $lines[] = 'Improve the patch accordingly.';
        } else {
            $lines[] = 'The proposer asks: "' . $ask . '"';
        }
        $lines[] = 'Respond with the propose_patch tool. Use the clauseIds above verbatim.';
        $lines[] = 'Operations: replace_clause (new text for a clause), strike_clause (remove it, text null),';
        $lines[] = 'insert_clause_after (new clause after the given one; clauseId null inserts at the top).';

        return implode("\n", $lines);
    }
}
