<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\StorePingsService;
use App\Domain\Ping;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StorePingsServiceTest extends TestCase
{
    public function test_duplicate_vehicle_and_time_is_inserted_once(): void
    {
        $service = app(StorePingsService::class);
        $ping = new Ping('v1', 50.0, 30.0, 1_718_000_000_000, 10.0);

        $first = $service->storeBatch([$ping]);
        $second = $service->storeBatch([$ping]);

        self::assertSame(1, $first);
        self::assertSame(0, $second);
    }

    public function test_distinct_timestamps_are_all_stored(): void
    {
        $service = app(StorePingsService::class);

        $service->storeBatch([
            new Ping('v1', 50.0, 30.0, 1_718_000_000_000, 10.0),
            new Ping('v1', 50.1, 30.1, 1_718_000_001_000, 11.0),
        ]);

        self::assertSame(2, DB::table('vehicle_pings')->count());
    }
}
