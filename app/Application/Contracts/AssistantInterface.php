<?php

declare(strict_types=1);

namespace App\Application\Contracts;

interface AssistantInterface
{
    /**
     * Answer a free-text fleet question, yielding the reply as text chunks
     * suitable for streaming to the client.
     *
     * @return iterable<string>
     */
    public function stream(string $question): iterable;
}
