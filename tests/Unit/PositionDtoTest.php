<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Dto\PositionDto;
use App\Domain\Ping;
use PHPUnit\Framework\TestCase;

final class PositionDtoTest extends TestCase
{
    public function test_from_ping_converts_ts_millis_to_iso(): void
    {
        // 2024-06-10T06:13:20Z
        $ping = new Ping('v1', 50.0, 30.0, 1_718_000_000_000, 9.0);

        $dto = PositionDto::fromPing($ping);

        self::assertSame(1_718_000_000_000, $dto->ts);
        self::assertStringStartsWith('2024-06-10T06:13:20', $dto->recordedAt);
    }

    public function test_array_round_trip(): void
    {
        $dto = PositionDto::fromPing(new Ping('v1', 1.0, 2.0, 1_000, 3.0));

        self::assertEquals($dto, PositionDto::fromArray($dto->toArray()));
    }
}
