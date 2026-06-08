<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Assistant\AssistantTools;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantToolsTest extends TestCase
{
    private function tools(): AssistantTools
    {
        return app(AssistantTools::class);
    }

    private function insertPing(string $vehicleId, float $lat, float $lng, ?float $speed): void
    {
        DB::table('vehicle_pings')->insert([
            'vehicle_id' => $vehicleId,
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speed,
            'recorded_at' => '2024-06-01 10:00:00+00',
        ]);
    }

    public function test_definitions_expose_expected_tools(): void
    {
        $names = array_column($this->tools()->definitions(), 'name');

        self::assertEqualsCanonicalizing(
            ['total_vehicles', 'count_vehicles_in_city', 'fastest_vehicle', 'average_speed', 'vehicle_latest'],
            $names,
        );
    }

    public function test_dispatch_total_vehicles(): void
    {
        $this->insertPing('v1', 50.45, 30.52, 10.0);

        self::assertSame(['total' => 1], $this->tools()->dispatch('total_vehicles', []));
    }

    public function test_dispatch_count_vehicles_in_city(): void
    {
        $this->insertPing('v1', 50.45, 30.52, 10.0);

        $result = $this->tools()->dispatch('count_vehicles_in_city', ['city' => 'Kyiv']);

        self::assertTrue($result['matched']);
        self::assertSame(1, $result['count']);
    }

    public function test_dispatch_fastest_vehicle_returns_position(): void
    {
        $this->insertPing('v1', 50.45, 30.52, 42.0);

        $result = $this->tools()->dispatch('fastest_vehicle', []);

        self::assertTrue($result['found']);
        self::assertSame('v1', $result['vehicle_id']);
        self::assertSame(42.0, $result['speed']);
    }

    public function test_dispatch_vehicle_latest_unknown_returns_not_found(): void
    {
        self::assertSame(['found' => false], $this->tools()->dispatch('vehicle_latest', ['vehicle_id' => 'ghost']));
    }

    public function test_dispatch_missing_required_param_returns_error(): void
    {
        self::assertArrayHasKey('error', $this->tools()->dispatch('count_vehicles_in_city', []));
    }

    public function test_dispatch_unknown_tool_returns_error(): void
    {
        self::assertSame(['error' => 'Unknown tool: nope'], $this->tools()->dispatch('nope', []));
    }
}
