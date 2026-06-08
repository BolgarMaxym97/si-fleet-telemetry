<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BoundingBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoundingBoxTest extends TestCase
{
    private function box(): BoundingBox
    {
        return new BoundingBox(minLat: 50.0, minLng: 30.0, maxLat: 51.0, maxLng: 31.0);
    }

    #[DataProvider('points')]
    public function test_contains(float $lat, float $lng, bool $expected): void
    {
        self::assertSame($expected, $this->box()->contains($lat, $lng));
    }

    /** @return array<string,array{0:float,1:float,2:bool}> */
    public static function points(): array
    {
        return [
            'inside' => [50.5, 30.5, true],
            'min corner (inclusive)' => [50.0, 30.0, true],
            'max corner (inclusive)' => [51.0, 31.0, true],
            'lat below' => [49.9, 30.5, false],
            'lat above' => [51.1, 30.5, false],
            'lng below' => [50.5, 29.9, false],
            'lng above' => [50.5, 31.1, false],
        ];
    }

    public function test_from_array(): void
    {
        $box = BoundingBox::fromArray(['min_lat' => 1, 'min_lng' => 2, 'max_lat' => 3, 'max_lng' => 4]);

        self::assertSame(1.0, $box->minLat);
        self::assertSame(4.0, $box->maxLng);
    }
}
