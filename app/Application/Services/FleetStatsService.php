<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\PingRepositoryInterface;
use App\Application\Dto\PositionDto;
use App\Domain\BoundingBox;

/**
 * Fleet-wide aggregates over each vehicle's latest position. The heavy lifting
 * (max / avg / count over the latest-per-vehicle set) runs in SQL via the
 * repository, so the whole fleet is never materialised in PHP.
 */
final readonly class FleetStatsService
{
    public function __construct(private PingRepositoryInterface $repository) {}

    public function totalVehicles(): int
    {
        return $this->repository->countDistinctVehicles();
    }

    /**
     * Count vehicles whose latest position falls inside the named city's box.
     *
     * @return array{city:string,matched:bool,count:int}
     */
    public function countInCity(string $city): array
    {
        $slug = strtolower(trim($city));
        $boxes = config('cities');

        if (! isset($boxes[$slug])) {
            return ['city' => $city, 'matched' => false, 'count' => 0];
        }

        $count = $this->repository->countLatestInBox(BoundingBox::fromArray($boxes[$slug]));

        return ['city' => $city, 'matched' => true, 'count' => $count];
    }

    /** Vehicle with the highest current speed, or null when no speeds are known. */
    public function fastest(): ?PositionDto
    {
        return $this->repository->fastestLatest();
    }

    /**
     * Mean current speed across vehicles that report one.
     *
     * @return array{average:?float,sampled:int,total:int}
     */
    public function averageSpeed(): array
    {
        return $this->repository->averageSpeedOfLatest();
    }
}
