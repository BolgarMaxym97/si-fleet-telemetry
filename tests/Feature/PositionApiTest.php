<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PositionApiTest extends TestCase
{
    private function insertPing(string $vehicleId, string $recordedAt, float $lat, ?float $speed = null): void
    {
        DB::table('vehicle_pings')->insert([
            'vehicle_id' => $vehicleId,
            'lat' => $lat,
            'lng' => 30.0,
            'speed' => $speed,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_latest_returns_most_recent_position(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 50.5);
        $this->insertPing('v1', '2024-06-01 10:05:00+00', lat: 51.5);

        $this->getJson('/api/vehicles/v1/positions/latest')
            ->assertOk()
            ->assertJsonPath('data.vehicle_id', 'v1')
            ->assertJsonPath('data.lat', 51.5);
    }

    public function test_latest_unknown_vehicle_returns_404(): void
    {
        $this->getJson('/api/vehicles/ghost/positions/latest')->assertNotFound();
    }

    public function test_track_is_ordered_and_limited(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 1.5);
        $this->insertPing('v1', '2024-06-01 10:01:00+00', lat: 2.5);
        $this->insertPing('v1', '2024-06-01 10:02:00+00', lat: 3.5);

        $response = $this->getJson('/api/vehicles/v1/track?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Ascending by recorded_at => earliest two points.
        self::assertSame(1.5, $response->json('data.0.lat'));
        self::assertSame(2.5, $response->json('data.1.lat'));
    }

    public function test_track_respects_time_window(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 1.5);
        $this->insertPing('v1', '2024-06-01 12:00:00+00', lat: 2.5);

        $this->getJson('/api/vehicles/v1/track?from=2024-06-01T11:00:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lat', 2.5);
    }

    public function test_track_known_vehicle_with_empty_window_returns_200_empty(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 1.5);

        // Window excludes the only point: known vehicle, no rows => 200 + [].
        $this->getJson('/api/vehicles/v1/track?from=2024-06-01T11:00:00Z')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_track_unknown_vehicle_returns_404(): void
    {
        $this->getJson('/api/vehicles/ghost/track')->assertNotFound();
    }

    public function test_track_invalid_limit_returns_422(): void
    {
        $this->getJson('/api/vehicles/v1/track?limit=0')->assertStatus(422);
    }

    public function test_index_returns_one_position_per_vehicle(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 1.5);
        $this->insertPing('v1', '2024-06-01 10:05:00+00', lat: 2.5);
        $this->insertPing('v2', '2024-06-01 10:00:00+00', lat: 9.5);

        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_does_not_underreport_with_partially_warmed_cache(): void
    {
        $this->insertPing('v1', '2024-06-01 10:00:00+00', lat: 1.5);
        $this->insertPing('v2', '2024-06-01 10:00:00+00', lat: 9.5);

        // Warm the latest-cache for v1 only (single-vehicle lookup back-fills it).
        $this->getJson('/api/vehicles/v1/positions/latest')->assertOk();

        // Fleet view must still report both vehicles, not just the cached one.
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
