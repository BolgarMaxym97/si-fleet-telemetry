<?php

declare(strict_types=1);

namespace App\Infrastructure\Assistant;

use Anthropic\Client;
use Anthropic\Messages\InputJSONDelta;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ToolUseBlock;
use App\Application\Assistant\AssistantTools;
use App\Application\Contracts\AssistantInterface;
use Generator;

/**
 * Claude-backed implementation of the assistant. Runs the agentic tool loop
 * against the Messages API with real token streaming: each turn is consumed via
 * createStream(), text deltas are forwarded to the caller as they arrive (true
 * SSE), while tool_use blocks are accumulated from the same event stream so the
 * loop can execute tools and continue.
 */
final readonly class ClaudeAssistant implements AssistantInterface
{
    private const SYSTEM = <<<'PROMPT'
        You are the assistant for a vehicle fleet telemetry system. Answer questions
        about live vehicle positions, counts, locations, and speeds.

        Always use the provided tools to get live data — never guess or rely on prior
        knowledge for counts, speeds, or locations. If a tool reports that data is
        unknown (matched: false, found: false), say so plainly. Be concise and direct;
        give the number or fact the user asked for. Speeds are in the unit stored by
        the producer (typically km/h). If a question is unrelated to the fleet, say you
        can only answer fleet telemetry questions.
        PROMPT;

    private const MAX_TOOL_ROUNDS = 5;

    public function __construct(
        private Client $client,
        private AssistantTools $tools,
        private string $model,
        private int $maxTokens = 1024,
    ) {}

    /** @return iterable<string> */
    public function stream(string $question): iterable
    {
        $messages = [
            ['role' => 'user', 'content' => $question],
        ];
        $tools = $this->toolDefinitions();

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            // runTurn streams text deltas to the caller via `yield from`; its
            // return value carries the control data the loop needs.
            [$stopReason, $assistantContent, $toolResults] = yield from $this->runTurn($tools, $messages);

            if ($stopReason !== 'tool_use') {
                return; // Final answer already streamed during the turn.
            }

            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        yield 'Sorry — I was unable to resolve that within the tool limit.';
    }

    /**
     * Consume one streamed model turn. Yields visible text as it arrives and,
     * once the stream ends, returns the stop reason plus the reconstructed
     * assistant content and any tool_result blocks for the next round.
     *
     * @param  list<array<string,mixed>>  $tools
     * @param  list<array<string,mixed>>  $messages
     * @return Generator<string,never,never,array{0:?string,1:list<array<string,mixed>>,2:list<array<string,mixed>>}>
     */
    private function runTurn(array $tools, array $messages): Generator
    {
        $stream = $this->client->messages->createStream(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: [
                ['type' => 'text', 'text' => self::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            tools: $tools,
            thinking: ['type' => 'disabled'],
            messages: $messages,
        );

        /** @var array<int,array<string,mixed>> $blocks indexed by content-block position */
        $blocks = [];
        $stopReason = null;

        foreach ($stream as $event) {
            if ($event instanceof RawContentBlockStartEvent) {
                $block = $event->contentBlock;
                $blocks[$event->index] = $block instanceof ToolUseBlock
                    ? ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'json' => '']
                    : ['type' => 'text', 'text' => ''];

                continue;
            }

            if ($event instanceof RawContentBlockDeltaEvent) {
                $delta = $event->delta;
                if ($delta instanceof TextDelta) {
                    $blocks[$event->index]['text'] = ($blocks[$event->index]['text'] ?? '').$delta->text;
                    yield $delta->text; // live token → one SSE frame
                } elseif ($delta instanceof InputJSONDelta) {
                    $blocks[$event->index]['json'] = ($blocks[$event->index]['json'] ?? '').$delta->partialJSON;
                }

                continue;
            }

            if ($event instanceof RawMessageDeltaEvent) {
                $stopReason = $event->delta->stopReason ?? $stopReason;
            }
        }

        ksort($blocks);

        return [$stopReason, $this->assistantContent($blocks), $this->toolResults($blocks)];
    }

    /**
     * Rebuild the assistant turn (text + tool_use blocks) so it can be appended
     * to the message history for the next round.
     *
     * @param  array<int,array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    private function assistantContent(array $blocks): array
    {
        $content = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'tool_use') {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $this->decodeInput($block['json']),
                ];
            } elseif ($block['type'] === 'text' && $block['text'] !== '') {
                $content[] = ['type' => 'text', 'text' => $block['text']];
            }
        }

        return $content;
    }

    /**
     * Execute every accumulated tool_use block and build the matching
     * tool_result blocks for the next user turn.
     *
     * @param  array<int,array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    private function toolResults(array $blocks): array
    {
        $results = [];
        foreach ($blocks as $block) {
            if ($block['type'] !== 'tool_use') {
                continue;
            }

            $output = $this->tools->dispatch($block['name'], $this->decodeInput($block['json']));
            $results[] = [
                'type' => 'tool_result',
                'toolUseID' => $block['id'],
                'content' => json_encode($output, JSON_THROW_ON_ERROR),
                'isError' => isset($output['error']),
            ];
        }

        return $results;
    }

    /**
     * Tool input arrives as a partial-JSON stream; an empty string means a
     * no-argument call.
     *
     * @return array<string,mixed>
     */
    private function decodeInput(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Translate the SDK-agnostic tool schemas into the PHP SDK's camelCase
     * `inputSchema` shape.
     *
     * @return list<array<string,mixed>>
     */
    private function toolDefinitions(): array
    {
        return array_map(
            static fn (array $def): array => [
                'name' => $def['name'],
                'description' => $def['description'],
                'inputSchema' => $def['input_schema'],
            ],
            $this->tools->definitions(),
        );
    }
}
