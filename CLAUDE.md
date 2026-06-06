# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Read/storage side of a vehicle-tracking pipeline, **downstream of
`si-fleet-mqtt-bridge`** (a sibling repo that produces geo-pings to Kafka topic
`vehicle.pings`). This service consumes that topic, stores history in
TimescaleDB, caches the latest position per vehicle in Redis, and serves a read
REST API. Laravel 13 / PHP 8.4.

```
Kafka vehicle.pings ─► consumer (kafka:consume-pings) ─► TimescaleDB (history, dedup)
                                     └───────────────► Redis (latest position)
                                                            ▲
                              REST API (Laravel) ───────────┘  latest: Redis-first, DB fallback
```

## Project skills — read first

Detailed, accurate domain knowledge lives in `.claude/skills/` (one folder per
skill, each with a `SKILL.md`). **Load the matching skill before working in an
area**: `telemetry-architecture`, `telemetry-ingestion`,
`telemetry-storage`, `telemetry-cache`, `telemetry-api`, `telemetry-metrics`,
`telemetry-testing`. Start with `telemetry-architecture` if unsure.

## Commands

The stack runs in Docker and **requires the `si-fleet-mqtt-bridge` stack up
first** — this compose joins its external network (`fleet-mqtt-bridge_default`)
to reach `kafka:9092`.

```bash
make up          # build + start (timescaledb, redis, app, consumer)
make migrate     # artisan migrate --force
make fresh       # artisan migrate:fresh --force
make logs        # tail consumer + app
make consume     # run the consumer in foreground (debug)
make psql        # psql into the fleet DB
make redis-cli
make down

make test                                                    # full suite
docker compose exec app php artisan test --filter=PositionApiTest   # single class
docker compose exec app php artisan test tests/Unit/PingTest.php     # single file
```

API is on `http://localhost:8081` (8000 in-container). `/metrics` (Prometheus
text) on the same app process.

Lint/format: Laravel Pint is in `require-dev` (`./vendor/bin/pint`).

## Architecture — the parts that span files

**DDD layering under `app/`** (dependencies point inward; Domain has zero
framework imports):
- `Domain` — `Ping` value object (the validation **trust boundary**),
  `InvalidPingException`.
- `Application` — `Contracts/` (interfaces), `Dto/PositionDto`, `Services/`
  (`StorePingsService` write use case, `PingQueryService` read use cases).
- `Infrastructure` — `Persistence/` (Eloquent repo + `VehiclePing` model),
  `Cache/RedisLatestPositionCache`, `Metrics/PipelineMetrics`.
- `Http` / `Console` — thin controllers, `TrackRequest`, `PositionResource`,
  middleware, the `kafka:consume-pings` command.

Interfaces are bound to implementations in `app/Providers/AppServiceProvider.php`.
**Put business logic in Domain or an Application service — never in controllers,
the console command, or migrations.** Depend on the Application interface, not the
concrete Infrastructure class.

**Two processes, one image.** `app` (HTTP) and `consumer` (Kafka loop) are
separate OS processes off the same Docker image. Anything shared between them
must live in Redis — both the latest-position cache **and** the Prometheus
`CollectorRegistry` (registered Redis-backed in `AppServiceProvider`, with
`registerDefaultMetrics` disabled to avoid an eager Redis write at image-build
time).

## Key invariants (do not break)

- **Dedup is a single DB-level mechanism**: unique index
  `(vehicle_id, recorded_at)` + `insertOrIgnore` (`ON CONFLICT DO NOTHING`).
  Absorbs MQTT QoS1 duplicates idempotently. The duplicate count derives from
  `count(pings) - inserted`.
- **Write order: DB first, then cache.** The Redis cache is a rebuildable
  projection of the DB (back-filled on a read miss); a cache failure must not
  lose data.
- **Redis latest cache is monotonic, no TTL.** `put` never moves a vehicle
  backward — if the stored `ts >=` incoming `ts`, it keeps the old value.
- **`ts` is epoch millis** everywhere internally (matches the producer
  contract); `recorded_at` is the ISO8601/timestamptz API-facing form. The
  `PositionResource` deliberately does **not** expose `ts`.
- **TimescaleDB is optional and auto-detected** by the migration: present →
  hypertable; absent → plain Postgres table with identical columns/indexes/
  queries. Never branch on Timescale in application code. A unique index on the
  hypertable must include the partition column `recorded_at`.
- **Producer contract**: `Ping` mirrors
  `si-fleet-mqtt-bridge/fleet_bridge/models.py::Ping`
  (`vehicle_id, lat, lng, ts`, optional `speed`). Keep them in sync.

## Conventions

- `declare(strict_types=1)` in every file; full type hints; `final readonly`
  value objects/DTOs; `$fillable` whitelist (no `$guarded = []`).
- New schema → new migration; raw SQL is used in the pings migration because of
  the hypertable + partial-column unique index. Prefer the Eloquent builder for
  queries; raw SQL only for Postgres-specific features (e.g. `DISTINCT ON`).
- Prometheus metric labels use the **route pattern**, never high-cardinality
  values (vehicle id, raw URI). Define all metrics in `PipelineMetrics`, names
  prefixed `telemetry_`.

## Testing

PHPUnit (not Pest). `tests/TestCase.php` does **not** use `RefreshDatabase`
(Timescale hypertables don't isolate under its single transaction): it runs
`migrate:fresh` once per process, then `TRUNCATE vehicle_pings` + Redis
`flushdb` per test. Tests target the `fleet_test` DB and Redis db 15. New
stateful tables need a truncate added to `setUp`.

## Monitoring

Prometheus + Grafana are **not** in this compose — they are the single shared
stack in `si-fleet-mqtt-bridge` (9090 / 3000), which scrapes `app:8000/metrics`
over the shared network. The telemetry dashboard source lives here at
`docker/grafana/dashboards/fleet-telemetry.json` and is deployed by copying it
into the bridge's dashboards dir.
