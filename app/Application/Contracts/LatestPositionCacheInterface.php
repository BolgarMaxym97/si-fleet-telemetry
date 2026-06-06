<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Dto\PositionDto;

interface LatestPositionCacheInterface
{
    /** Store one position; must not move a vehicle backward (older ts ignored). */
    public function put(PositionDto $position): void;

    /** @param list<PositionDto> $positions */
    public function putMany(array $positions): void;

    public function get(string $vehicleId): ?PositionDto;

    /** @return list<PositionDto> empty when the cache is cold */
    public function getAll(): array;
}
