<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // TimescaleDB hypertables don't isolate cleanly under RefreshDatabase's
        // single wrapping transaction, so reset state explicitly instead:
        // migrate once per process, then truncate + flush Redis per test.
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        DB::statement('TRUNCATE TABLE vehicle_pings');
        Redis::connection()->flushdb();
    }
}
