<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Contracts\AssistantInterface;
use Tests\TestCase;

final class AssistantApiTest extends TestCase
{
    private function fakeAssistant(string ...$chunks): void
    {
        $this->app->bind(AssistantInterface::class, fn (): AssistantInterface => new class($chunks) implements AssistantInterface
        {
            /** @param list<string> $chunks */
            public function __construct(private readonly array $chunks) {}

            public function stream(string $question): iterable
            {
                yield from $this->chunks;
            }
        });
    }

    public function test_ask_streams_sse_frames(): void
    {
        $this->fakeAssistant('Hello', ' world');

        $response = $this->postJson('/api/assistant/ask', ['question' => 'hi']);

        $response->assertOk();
        self::assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        self::assertStringContainsString('data: Hello', $body);
        self::assertStringContainsString('data:  world', $body);
        self::assertStringContainsString('event: done', $body);
    }

    public function test_ask_requires_question(): void
    {
        $this->postJson('/api/assistant/ask', [])->assertStatus(422);
    }

    public function test_ask_rejects_blank_question(): void
    {
        $this->postJson('/api/assistant/ask', ['question' => ''])->assertStatus(422);
    }
}
