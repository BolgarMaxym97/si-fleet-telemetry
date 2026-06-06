<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Contracts\LatestPositionCacheInterface;
use App\Application\Dto\PositionDto;
use Illuminate\Support\Facades\Redis;

/**
 * Latest position per vehicle, cached in Redis.
 *
 * - Key   fleet:latest:{vehicle_id} -> JSON PositionDto
 * - Index fleet:vehicles            -> SET of known vehicle ids (for getAll)
 *
 * No TTL: the latest position is always wanted. A monotonic ts guard prevents an
 * out-of-order / redelivered batch from moving a vehicle backward.
 */
final class RedisLatestPositionCache implements LatestPositionCacheInterface
{
    private const KEY_PREFIX = 'fleet:latest:';

    private const INDEX_KEY = 'fleet:vehicles';

    public function put(PositionDto $position): void
    {
        $key = self::KEY_PREFIX.$position->vehicleId;

        $current = Redis::get($key);
        if ($current !== null) {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode((string) $current, true);
            if (isset($decoded['ts']) && (int) $decoded['ts'] >= $position->ts) {
                return; // stored value is newer or equal — keep it
            }
        }

        Redis::set($key, json_encode($position->toArray()));
        Redis::sadd(self::INDEX_KEY, $position->vehicleId);
    }

    public function putMany(array $positions): void
    {
        foreach ($this->newestPerVehicle($positions) as $position) {
            $this->put($position);
        }
    }

    public function get(string $vehicleId): ?PositionDto
    {
        $raw = Redis::get(self::KEY_PREFIX.$vehicleId);

        return $raw !== null ? PositionDto::fromArray(json_decode((string) $raw, true)) : null;
    }

    public function getAll(): array
    {
        $ids = Redis::smembers(self::INDEX_KEY);
        if ($ids === []) {
            return [];
        }

        $keys = array_map(static fn (string $id): string => self::KEY_PREFIX.$id, $ids);

        $positions = [];
        foreach (Redis::mget($keys) as $raw) {
            if ($raw !== null) {
                $positions[] = PositionDto::fromArray(json_decode((string) $raw, true));
            }
        }

        return $positions;
    }

    /**
     * Collapse a batch to the newest ts per vehicle so a single put wins.
     *
     * @param list<PositionDto> $positions
     * @return array<string,PositionDto>
     */
    private function newestPerVehicle(array $positions): array
    {
        $newest = [];
        foreach ($positions as $position) {
            $existing = $newest[$position->vehicleId] ?? null;
            if ($existing === null || $position->ts > $existing->ts) {
                $newest[$position->vehicleId] = $position;
            }
        }

        return $newest;
    }
}
