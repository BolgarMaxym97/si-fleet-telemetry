<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exceptions\InvalidPingException;

/**
 * Immutable telemetry ping. Mirrors the producer contract
 * (si-fleet-mqtt-bridge: fleet_bridge/models.py::Ping). Pure domain — no framework.
 *
 * Validation here is the trust boundary: a malformed Kafka payload must never
 * reach persistence.
 */
final readonly class Ping
{
    public function __construct(
        public string $vehicleId,
        public float $lat,
        public float $lng,
        public int $ts,
        public ?float $speed = null,
    ) {
        if ($vehicleId === '' || mb_strlen($vehicleId) > 64) {
            throw new InvalidPingException('vehicle_id must be 1..64 chars');
        }
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidPingException("lat out of range: {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidPingException("lng out of range: {$lng}");
        }
        if ($ts < 0) {
            throw new InvalidPingException("ts must be >= 0: {$ts}");
        }
        if ($speed !== null && $speed < 0.0) {
            throw new InvalidPingException("speed must be >= 0: {$speed}");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['vehicle_id', 'lat', 'lng', 'ts'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidPingException("missing field: {$required}");
            }
        }

        return new self(
            vehicleId: (string) $data['vehicle_id'],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            ts: (int) $data['ts'],
            speed: isset($data['speed']) ? (float) $data['speed'] : null,
        );
    }
}
