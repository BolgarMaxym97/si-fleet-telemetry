<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Contracts\AssistantInterface;
use App\Http\Requests\AssistantRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssistantController
{
    public function __construct(private readonly AssistantInterface $assistant) {}

    /** Stream a natural-language answer to a fleet question as Server-Sent Events. */
    public function ask(AssistantRequest $request): StreamedResponse
    {
        $question = $request->question();

        return response()->stream(
            function () use ($question): void {
                foreach ($this->assistant->stream($question) as $chunk) {
                    echo 'data: '.str_replace("\n", "\ndata: ", $chunk)."\n\n";
                    $this->flush();
                }

                echo "event: done\ndata: [DONE]\n\n";
                $this->flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function flush(): void
    {
        // Push the current buffer downstream without tearing it down, so this
        // stays well-behaved under nested output buffering (e.g. test capture).
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
