<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exceptions\InvalidPingException;
use App\Domain\Ping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PingTest extends TestCase
{
    public function test_builds_from_array(): void
    {
        $ping = Ping::fromArray([
            'vehicle_id' => 'kyiv-1',
            'lat' => 50.45,
            'lng' => 30.52,
            'ts' => 1_718_000_000_000,
            'speed' => 12.5,
        ]);

        self::assertSame('kyiv-1', $ping->vehicleId);
        self::assertSame(50.45, $ping->lat);
        self::assertSame(1_718_000_000_000, $ping->ts);
        self::assertSame(12.5, $ping->speed);
    }

    public function test_speed_is_optional(): void
    {
        $ping = Ping::fromArray(['vehicle_id' => 'v', 'lat' => 0, 'lng' => 0, 'ts' => 0]);

        self::assertNull($ping->speed);
    }

    public function test_missing_field_is_rejected(): void
    {
        $this->expectException(InvalidPingException::class);

        Ping::fromArray(['vehicle_id' => 'v', 'lat' => 0, 'lng' => 0]); // no ts
    }

    #[DataProvider('invalidValues')]
    public function test_out_of_range_values_are_rejected(float $lat, float $lng, int $ts, ?float $speed): void
    {
        $this->expectException(InvalidPingException::class);

        new Ping('v', $lat, $lng, $ts, $speed);
    }

    /** @return array<string,array{0:float,1:float,2:int,3:?float}> */
    public static function invalidValues(): array
    {
        return [
            'lat too high' => [91.0, 0.0, 0, null],
            'lat too low' => [-91.0, 0.0, 0, null],
            'lng too high' => [0.0, 181.0, 0, null],
            'lng too low' => [0.0, -181.0, 0, null],
            'negative ts' => [0.0, 0.0, -1, null],
            'negative speed' => [0.0, 0.0, 0, -5.0],
        ];
    }

    public function test_empty_vehicle_id_is_rejected(): void
    {
        $this->expectException(InvalidPingException::class);

        new Ping('', 0.0, 0.0, 0);
    }
}
