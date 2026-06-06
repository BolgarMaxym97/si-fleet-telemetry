<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Support\PingBatch;
use App\Domain\Ping;
use PHPUnit\Framework\TestCase;

final class PingBatchTest extends TestCase
{
    private function ping(string $vehicleId = 'v1'): Ping
    {
        return new Ping(vehicleId: $vehicleId, lat: 50.0, lng: 30.0, ts: 1_718_000_000_000);
    }

    public function test_add_count_and_drain_reset_the_buffer(): void
    {
        $batch = new PingBatch;
        self::assertTrue($batch->isEmpty());

        $batch->add($this->ping(), now: 100.0);
        $batch->add($this->ping(), now: 100.0);
        self::assertSame(2, $batch->count());
        self::assertFalse($batch->isEmpty());

        $drained = $batch->drain();
        self::assertCount(2, $drained);
        self::assertSame(0, $batch->count());
        self::assertTrue($batch->isEmpty());
    }

    public function test_should_flush_when_size_reached(): void
    {
        $batch = new PingBatch;
        $batch->add($this->ping(), now: 100.0);
        $batch->add($this->ping(), now: 100.0);

        // Same wall-clock as first add → no age trigger; size threshold drives it.
        self::assertTrue($batch->shouldFlush(maxSize: 2, maxAgeSeconds: 60.0, now: 100.0));
        self::assertFalse($batch->shouldFlush(maxSize: 3, maxAgeSeconds: 60.0, now: 100.0));
    }

    public function test_should_flush_when_partial_buffer_ages_out(): void
    {
        $batch = new PingBatch;
        $batch->add($this->ping(), now: 100.0);

        // Below size, but the oldest ping is older than the max age.
        self::assertFalse($batch->shouldFlush(maxSize: 10, maxAgeSeconds: 1.0, now: 100.5));
        self::assertTrue($batch->shouldFlush(maxSize: 10, maxAgeSeconds: 1.0, now: 101.0));
    }

    public function test_empty_buffer_never_flushes(): void
    {
        $batch = new PingBatch;

        self::assertFalse($batch->shouldFlush(maxSize: 1, maxAgeSeconds: 0.0, now: 999.0));
    }
}
