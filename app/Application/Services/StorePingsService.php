<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\LatestPositionCacheInterface;
use App\Application\Contracts\PingRepositoryInterface;
use App\Application\Dto\PositionDto;
use App\Domain\Ping;
use App\Infrastructure\Metrics\PipelineMetrics;

final readonly class StorePingsService
{
    public function __construct(
        private PingRepositoryInterface $repository,
        private LatestPositionCacheInterface $cache,
        private PipelineMetrics $metrics,
    ) {
    }

    /**
     * Persist a batch durably (dedup via ON CONFLICT DO NOTHING), then refresh
     * the latest-position cache. Returns rows actually inserted.
     *
     * @param list<Ping> $pings
     */
    public function storeBatch(array $pings): int
    {
        if ($pings === []) {
            return 0;
        }

        $start = microtime(true);

        $inserted = $this->repository->insertIgnoreBatch($pings);

        $this->cache->putMany(array_map(
            static fn (Ping $ping): PositionDto => PositionDto::fromPing($ping),
            $pings,
        ));

        $this->metrics->observeStore(microtime(true) - $start);
        $this->metrics->inserted($inserted);
        $this->metrics->duplicates(count($pings) - $inserted);

        return $inserted;
    }
}
