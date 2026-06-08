<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Contracts\PingRepositoryInterface;
use App\Application\Dto\PositionDto;
use App\Domain\BoundingBox;
use App\Domain\Ping;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    public function fastestLatest(): ?PositionDto
    {
        $row = DB::query()
            ->fromSub($this->latestPerVehicleSub(), 'latest')
            ->whereNotNull('speed')
            ->orderByDesc('speed')
            ->first();

        return $row !== null ? $this->rowToDto($row) : null;
    }

    public function averageSpeedOfLatest(): array
    {
        // COUNT(speed) ignores NULLs (vehicles without a reported speed);
        // COUNT(*) is the whole fleet; AVG(speed) is NULL when no speeds exist.
        $agg = DB::query()
            ->fromSub($this->latestPerVehicleSub(), 'latest')
            ->selectRaw('AVG(speed) AS average, COUNT(speed) AS sampled, COUNT(*) AS total')
            ->first();

        return [
            'average' => $agg?->average !== null ? round((float) $agg->average, 2) : null,
            'sampled' => (int) ($agg?->sampled ?? 0),
            'total' => (int) ($agg?->total ?? 0),
        ];
    }

    public function countLatestInBox(BoundingBox $box): int
    {
        return DB::query()
            ->fromSub($this->latestPerVehicleSub(), 'latest')
            ->whereBetween('lat', [$box->minLat, $box->maxLat])
            ->whereBetween('lng', [$box->minLng, $box->maxLng])
            ->count();
    }

    /**
     * Subquery yielding one row per vehicle — its most recent ping. Shared by the
     * fleet aggregates so they run in the DB instead of loading every vehicle.
     */
    private function latestPerVehicleSub(): Builder
    {
        return VehiclePing::query()
            ->selectRaw('DISTINCT ON (vehicle_id) vehicle_id, lat, lng, speed, recorded_at')
            ->orderBy('vehicle_id')
            ->orderByDesc('recorded_at')
            ->toBase();
    }

    private function rowToDto(object $row): PositionDto
    {
        $recordedAt = CarbonImmutable::parse((string) $row->recorded_at);

        return new PositionDto(
            vehicleId: (string) $row->vehicle_id,
            lat: (float) $row->lat,
            lng: (float) $row->lng,
            speed: $row->speed !== null ? (float) $row->speed : null,
            ts: $recordedAt->getTimestampMs(),
            recordedAt: $recordedAt->toIso8601String(),
        );
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
