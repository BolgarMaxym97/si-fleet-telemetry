<?php

declare(strict_types=1);

namespace App\Console\Support;

use App\Domain\Ping;

/**
 * In-memory accumulator for the Kafka consumer's batch buffer.
 *
 * Framework-free so the flush decision is unit-testable without Kafka. Holds the
 * buffered pings and the wall-clock time the first one was added, which together
 * drive the size/age flush thresholds.
 */
final class PingBatch
{
    /** @var list<Ping> */
    private array $pings = [];

    private ?float $firstAddedAt = null;

    public function add(Ping $ping, float $now): void
    {
        if ($this->pings === []) {
            $this->firstAddedAt = $now;
        }

        $this->pings[] = $ping;
    }

    public function count(): int
    {
        return count($this->pings);
    }

    public function isEmpty(): bool
    {
        return $this->pings === [];
    }

    /**
     * Flush when the buffer is full, or when a non-empty buffer has aged past the
     * max age. Age is measured from the first buffered ping.
     */
    public function shouldFlush(int $maxSize, float $maxAgeSeconds, float $now): bool
    {
        if ($this->pings === []) {
            return false;
        }

        if ($this->count() >= $maxSize) {
            return true;
        }

        return ($now - (float) $this->firstAddedAt) >= $maxAgeSeconds;
    }

    /**
     * Return the buffered pings and reset the buffer.
     *
     * @return list<Ping>
     */
    public function drain(): array
    {
        $pings = $this->pings;

        $this->pings = [];
        $this->firstAddedAt = null;

        return $pings;
    }
}
