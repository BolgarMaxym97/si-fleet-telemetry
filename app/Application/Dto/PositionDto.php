<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Ping;
use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Read model for a vehicle position. Carries both `ts` (epoch millis, the
 * dedup/ordering key) and `recordedAt` (ISO8601, the API-facing timestamp).
 */
final readonly class PositionDto implements JsonSerializable
{
    public function __construct(
        public string $vehicleId,
        public float $lat,
        public float $lng,
        public ?float $speed,
        public int $ts,
        public string $recordedAt,
    ) {
    }

    public static function fromPing(Ping $ping): self
    {
        return new self(
            vehicleId: $ping->vehicleId,
            lat: $ping->lat,
            lng: $ping->lng,
            speed: $ping->speed,
            ts: $ping->ts,
            recordedAt: CarbonImmutable::createFromTimestampMs($ping->ts)->toIso8601String(),
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            vehicleId: (string) $data['vehicle_id'],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            speed: isset($data['speed']) ? (float) $data['speed'] : null,
            ts: (int) $data['ts'],
            recordedAt: (string) $data['recorded_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed' => $this->speed,
            'ts' => $this->ts,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
