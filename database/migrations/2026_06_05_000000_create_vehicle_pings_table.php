<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // TimescaleDB is optional: on a managed Timescale or the bundled
        // container we get a hypertable; on plain Postgres we degrade to a
        // regular table (same columns, same indexes, same queries).
        $hasTimescale = DB::selectOne(
            "SELECT 1 FROM pg_available_extensions WHERE name = 'timescaledb'"
        ) !== null;

        if ($hasTimescale) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
        }

        DB::statement(<<<'SQL'
            CREATE TABLE vehicle_pings (
                vehicle_id   varchar(64)      NOT NULL,
                lat          double precision NOT NULL,
                lng          double precision NOT NULL,
                speed        double precision NULL,
                recorded_at  timestamptz      NOT NULL,
                ingested_at  timestamptz      NOT NULL DEFAULT now()
            )
        SQL);

        // Time-series partitioning on event-time (Timescale only).
        if ($hasTimescale) {
            DB::statement("SELECT create_hypertable('vehicle_pings', 'recorded_at')");
        }

        // Dedup key. A unique index on a hypertable MUST include the partition column.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_vehicle_pings_vid_time
                ON vehicle_pings (vehicle_id, recorded_at)
        SQL);

        // Serves latest-per-vehicle + track-by-window queries.
        DB::statement(<<<'SQL'
            CREATE INDEX ix_vehicle_pings_vid_time_desc
                ON vehicle_pings (vehicle_id, recorded_at DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS vehicle_pings');
    }
};
