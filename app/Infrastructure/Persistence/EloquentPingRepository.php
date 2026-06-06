<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Contracts\PingRepositoryInterface;
use App\Application\Dto\PositionDto;
use App\Domain\Ping;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EloquentPingRepository implements PingRepositoryInterface
{
    public function insertIgnoreBatch(array $pings): int
    {
        if ($pings === []) {
            return 0;
        }

        $rows = array_map(static fn (Ping $ping): array => [
            'vehicle_id' => $ping->vehicleId,
            'lat' => $ping->lat,
            'lng' => $ping->lng,
            'speed' => $ping->speed,
            'recorded_at' => CarbonImmutable::createFromTimestampMs($ping->ts),
        ], $pings);

        // ON CONFLICT (vehicle_id, recorded_at) DO NOTHING — absorbs QoS1 dupes.
        return VehiclePing::insertOrIgnore($rows);
    }

    public function latestForVehicle(string $vehicleId): ?PositionDto
    {
        $model = VehiclePing::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();

        return $model !== null ? $this->toDto($model) : null;
    }

    public function vehicleExists(string $vehicleId): bool
    {
        return VehiclePing::query()
            ->where('vehicle_id', $vehicleId)
            ->exists();
    }

    public function countDistinctVehicles(): int
    {
        return VehiclePing::query()->distinct()->count('vehicle_id');
    }

    public function latestAll(): array
    {
        return VehiclePing::query()
            ->selectRaw('DISTINCT ON (vehicle_id) vehicle_id, lat, lng, speed, recorded_at')
            ->orderBy('vehicle_id')
            ->orderByDesc('recorded_at')
            ->get()
            ->map(fn (VehiclePing $model): PositionDto => $this->toDto($model))
            ->all();
    }

    public function track(string $vehicleId, ?CarbonInterface $from, ?CarbonInterface $to, int $limit): array
    {
        return VehiclePing::query()
            ->where('vehicle_id', $vehicleId)
            ->when($from !== null, fn ($query) => $query->where('recorded_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('recorded_at', '<=', $to))
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn (VehiclePing $model): PositionDto => $this->toDto($model))
            ->all();
    }

    private function toDto(VehiclePing $model): PositionDto
    {
        $recordedAt = $model->recorded_at;

        return new PositionDto(
            vehicleId: $model->vehicle_id,
            lat: $model->lat,
            lng: $model->lng,
            speed: $model->speed,
            ts: $recordedAt->getTimestampMs(),
            recordedAt: $recordedAt->toIso8601String(),
        );
    }
}
