<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Axis-aligned geographic bounding box. Pure value object: holds the four
 * edges and answers whether a (lat, lng) point falls inside (edges inclusive).
 * Used to answer "how many vehicles in <city>" without geocoding or PostGIS.
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLat,
        public float $minLng,
        public float $maxLat,
        public float $maxLng,
    ) {}

    /** @param array{min_lat:float|int,min_lng:float|int,max_lat:float|int,max_lng:float|int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            minLat: (float) $data['min_lat'],
            minLng: (float) $data['min_lng'],
            maxLat: (float) $data['max_lat'],
            maxLng: (float) $data['max_lng'],
        );
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat >= $this->minLat
            && $lat <= $this->maxLat
            && $lng >= $this->minLng
            && $lng <= $this->maxLng;
    }
}
