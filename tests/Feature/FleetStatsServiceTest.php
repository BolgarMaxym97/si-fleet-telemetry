<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\FleetStatsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FleetStatsServiceTest extends TestCase
{
    private function service(): FleetStatsService
    {
        return app(FleetStatsService::class);
    }

    private function insertPing(string $vehicleId, float $lat, float $lng, ?float $speed, string $recordedAt): void
    {
        DB::table('vehicle_pings')->insert([
            'vehicle_id' => $vehicleId,
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speed,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_total_vehicles_counts_distinct_vehicles(): void
    {
        $this->insertPing('v1', 50.4, 30.5, 10.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v1', 50.4, 30.5, 12.0, '2024-06-01 10:01:00+00');
        $this->insertPing('v2', 49.8, 24.0, 20.0, '2024-06-01 10:00:00+00');

        self::assertSame(2, $this->service()->totalVehicles());
    }

    public function test_fastest_uses_latest_position_and_ignores_null_speed(): void
    {
        // v1's latest speed is 5 (slower); v2 latest is 40 (fastest); v3 has no speed.
        $this->insertPing('v1', 50.4, 30.5, 99.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v1', 50.4, 30.5, 5.0, '2024-06-01 10:05:00+00');
        $this->insertPing('v2', 50.4, 30.5, 40.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v3', 50.4, 30.5, null, '2024-06-01 10:00:00+00');

        $fastest = $this->service()->fastest();

        self::assertNotNull($fastest);
        self::assertSame('v2', $fastest->vehicleId);
        self::assertSame(40.0, $fastest->speed);
    }

    public function test_fastest_returns_null_when_no_speeds(): void
    {
        $this->insertPing('v1', 50.4, 30.5, null, '2024-06-01 10:00:00+00');

        self::assertNull($this->service()->fastest());
    }

    public function test_average_speed_ignores_null_speeds(): void
    {
        $this->insertPing('v1', 50.4, 30.5, 10.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v2', 50.4, 30.5, 20.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v3', 50.4, 30.5, null, '2024-06-01 10:00:00+00');

        $result = $this->service()->averageSpeed();

        self::assertSame(15.0, $result['average']);
        self::assertSame(2, $result['sampled']);
        self::assertSame(3, $result['total']);
    }

    public function test_count_in_city_uses_bounding_box(): void
    {
        // Inside Kyiv box (~50.45, 30.5); v2 is in Lviv, outside Kyiv.
        $this->insertPing('v1', 50.45, 30.52, 10.0, '2024-06-01 10:00:00+00');
        $this->insertPing('v2', 49.84, 24.03, 10.0, '2024-06-01 10:00:00+00');

        $result = $this->service()->countInCity('Kyiv');

        self::assertTrue($result['matched']);
        self::assertSame(1, $result['count']);
    }

    public function test_count_in_city_is_case_insensitive(): void
    {
        $this->insertPing('v1', 50.45, 30.52, 10.0, '2024-06-01 10:00:00+00');

        self::assertSame(1, $this->service()->countInCity('  KYIV ')['count']);
    }

    public function test_count_in_unknown_city_reports_unmatched(): void
    {
        $this->insertPing('v1', 50.45, 30.52, 10.0, '2024-06-01 10:00:00+00');

        $result = $this->service()->countInCity('Atlantis');

        self::assertFalse($result['matched']);
        self::assertSame(0, $result['count']);
    }
}
