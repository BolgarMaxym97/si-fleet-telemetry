<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\LatestPositionCacheInterface;
use App\Application\Contracts\PingRepositoryInterface;
use App\Application\Dto\PositionDto;
use App\Infrastructure\Metrics\PipelineMetrics;
use Carbon\CarbonInterface;

final readonly class PingQueryService
{
    public function __construct(
        private PingRepositoryInterface $repository,
        private LatestPositionCacheInterface $cache,
        private PipelineMetrics $metrics,
    ) {
    }

    /** Latest position for one vehicle: Redis first, DB fallback + back-fill. */
    public function latest(string $vehicleId): ?PositionDto
    {
        $cached = $this->cache->get($vehicleId);
        if ($cached !== null) {
            $this->metrics->latestCache('hit');

            return $cached;
        }

        $this->metrics->latestCache('miss');

        $position = $this->repository->latestForVehicle($vehicleId);
        if ($position !== null) {
            $this->cache->put($position);
        }

        return $position;
    }

    /** Latest position for every vehicle: Redis first, DB fallback + back-fill. */
    public function latestAll(): array
    {
        // Serve the cache only when it covers the whole fleet. A partially-warmed
        // index (e.g. only vehicles queried via latest()) would otherwise
        // under-report; fall back to the DB and re-warm in that case.
        $cached = $this->cache->getAll();
        if ($cached !== [] && count($cached) === $this->repository->countDistinctVehicles()) {
            return $cached;
        }

        $positions = $this->repository->latestAll();
        foreach ($positions as $position) {
            $this->cache->put($position);
        }

        return $positions;
    }

    /** @return list<PositionDto> */
    public function track(string $vehicleId, ?CarbonInterface $from, ?CarbonInterface $to, int $limit): array
    {
        return $this->repository->track($vehicleId, $from, $to, $limit);
    }

    /** Whether the vehicle has any stored ping at all. */
    public function vehicleExists(string $vehicleId): bool
    {
        return $this->repository->vehicleExists($vehicleId);
    }
}
