<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Contracts\LatestPositionCacheInterface;
use App\Application\Dto\PositionDto;
use App\Domain\Ping;
use Tests\TestCase;

final class LatestPositionCacheTest extends TestCase
{
    private function cache(): LatestPositionCacheInterface
    {
        return app(LatestPositionCacheInterface::class);
    }

    private function position(string $vehicleId, int $ts, float $lat): PositionDto
    {
        return PositionDto::fromPing(new Ping($vehicleId, $lat, 0.0, $ts));
    }

    public function test_put_many_keeps_newest_ts_per_vehicle(): void
    {
        $this->cache()->putMany([
            $this->position('v1', 100, 1.0),
            $this->position('v1', 300, 3.0),
            $this->position('v1', 200, 2.0),
        ]);

        $hit = $this->cache()->get('v1');

        self::assertNotNull($hit);
        self::assertSame(300, $hit->ts);
        self::assertSame(3.0, $hit->lat);
    }

    public function test_older_ts_does_not_overwrite_newer(): void
    {
        $cache = $this->cache();
        $cache->put($this->position('v1', 300, 3.0));
        $cache->put($this->position('v1', 100, 1.0));

        self::assertSame(300, $cache->get('v1')->ts);
    }

    public function test_get_all_returns_known_vehicles(): void
    {
        $cache = $this->cache();
        $cache->put($this->position('v1', 100, 1.0));
        $cache->put($this->position('v2', 100, 2.0));

        $ids = array_map(static fn (PositionDto $p): string => $p->vehicleId, $cache->getAll());
        sort($ids);

        self::assertSame(['v1', 'v2'], $ids);
    }

    public function test_get_miss_returns_null(): void
    {
        self::assertNull($this->cache()->get('nope'));
    }
}
