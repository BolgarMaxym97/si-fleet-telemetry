<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Dto\PositionDto;
use App\Domain\BoundingBox;
use App\Domain\Ping;
use Carbon\CarbonInterface;

interface PingRepositoryInterface
{
    /**
     * Insert a batch, ignoring rows that collide on (vehicle_id, recorded_at).
     * Returns the number of rows actually inserted.
     *
     * @param  list<Ping>  $pings
     */
    public function insertIgnoreBatch(array $pings): int;

    public function latestForVehicle(string $vehicleId): ?PositionDto;

    /** Whether the vehicle has any stored ping at all. */
    public function vehicleExists(string $vehicleId): bool;

    /** Distinct vehicle count across all pings — drives the latest-cache completeness check. */
    public function countDistinctVehicles(): int;

    /** @return list<PositionDto> */
    public function latestAll(): array;

    /** @return list<PositionDto> */
    public function track(string $vehicleId, ?CarbonInterface $from, ?CarbonInterface $to, int $limit): array;

    /**
     * Fleet aggregates computed in SQL over each vehicle's latest position
     * (DISTINCT ON), so the whole fleet never has to be loaded into PHP.
     */

    /** Vehicle with the highest current speed, or null when none report a speed. */
    public function fastestLatest(): ?PositionDto;

    /**
     * Mean current speed across vehicles' latest positions.
     *
     * @return array{average:?float,sampled:int,total:int}
     */
    public function averageSpeedOfLatest(): array;

    /** Count vehicles whose latest position falls inside the box. */
    public function countLatestInBox(BoundingBox $box): int;
}
