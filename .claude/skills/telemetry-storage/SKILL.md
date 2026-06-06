---
name: telemetry-storage
description: Use when touching the database, the vehicle_pings schema/migration, the Eloquent repository, queries, or TimescaleDB in si-fleet-telemetry. Covers the hypertable, dedup index, latest/track queries, and the Timescale-optional migration. Triggers — EN: database, migration, schema, vehicle_pings, TimescaleDB, hypertable, index, repository, EloquentPingRepository, DISTINCT ON, latest query, track query, recorded_at, Postgres. UA: база, міграція, схема, гіпертаблиця, індекс, репозиторій, запит, Postgres, TimescaleDB.
---

# si-fleet-telemetry — Storage / database

## Table `vehicle_pings` (migration `2026_06_05_000000_create_vehicle_pings_table.php`)

```
vehicle_id   varchar(64)      NOT NULL
lat          double precision NOT NULL
lng          double precision NOT NULL
speed        double precision NULL
recorded_at  timestamptz      NOT NULL          -- device event-time
ingested_at  timestamptz      NOT NULL DEFAULT now()
```

No surrogate key, no Laravel timestamps. Row identity is the composite
`(vehicle_id, recorded_at)`. Migration is **raw SQL** (not the schema builder)
because of the hypertable + partial-column unique-index requirements.

**Indexes:**
- `uq_vehicle_pings_vid_time` UNIQUE `(vehicle_id, recorded_at)` — dedup key.
- `ix_vehicle_pings_vid_time_desc` `(vehicle_id, recorded_at DESC)` — serves
  latest-per-vehicle + track-by-window.

## TimescaleDB is optional and auto-detected

The migration probes `pg_available_extensions` for `timescaledb`:
- present → `CREATE EXTENSION` + `create_hypertable('vehicle_pings',
  'recorded_at')`. The unique index **must include** the partition column
  `recorded_at` — that is why the dedup key is `(vehicle_id, recorded_at)`.
- absent → plain Postgres table, identical columns/indexes/queries.

Same app code runs on the bundled Timescale container, plain local Postgres, or
managed Timescale Cloud (`DB_SSLMODE=require`). **Never branch on Timescale in
application code** — the table shape is identical either way.

## Model `App\Infrastructure\Persistence\VehiclePing`

Eloquent: `$timestamps = false`, `$incrementing = false`, `$keyType = 'string'`,
`$table = 'vehicle_pings'`. `$fillable` whitelist (no `$guarded = []`). Casts
`lat/lng/speed` → float, `recorded_at` → `immutable_datetime`.

## Repository `App\Infrastructure\Persistence\EloquentPingRepository`

Implements `PingRepositoryInterface`. Returns `PositionDto`, not models.

- `insertIgnoreBatch(list<Ping>): int` — maps Pings to rows, converting `ts`
  millis → `recorded_at` via `CarbonImmutable::createFromTimestampMs`, then
  `VehiclePing::insertOrIgnore($rows)`. Returns rows inserted.
- `latestForVehicle($id)` — `WHERE vehicle_id ORDER BY recorded_at DESC LIMIT 1`.
- `latestAll()` — Postgres `DISTINCT ON (vehicle_id) ... ORDER BY vehicle_id,
  recorded_at DESC` — one newest row per vehicle in one query (the only raw SQL
  fragment; Postgres-specific, unavoidable).
- `track($id, $from, $to, $limit)` — optional window via `->when()`,
  `ORDER BY recorded_at ASC`, `LIMIT`.
- `toDto()` rebuilds `ts` from the stored timestamp via `getTimestampMs()`.
  `timestamptz` is microsecond precision, so millis round-trip losslessly.

## Conventions when adding queries
- Prefer the Eloquent builder; raw SQL only for Postgres-specific features
  (like `DISTINCT ON`).
- Any new hot query needs a matching index; remember a unique index on the
  hypertable must include `recorded_at`.
- Schema change → new migration; keep up/down symmetric.

## Related
[[telemetry-ingestion]] · [[telemetry-cache]] · [[telemetry-testing]] · [[telemetry-architecture]]
