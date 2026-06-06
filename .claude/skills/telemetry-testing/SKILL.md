---
name: telemetry-testing
description: Use when writing or running tests for si-fleet-telemetry. Covers the PHPUnit setup, the deliberate test-isolation strategy (truncate + flushdb, NOT RefreshDatabase, because of Timescale hypertables), the fleet_test DB / Redis db 15, and what to test vs skip. Triggers — EN: test, PHPUnit, feature test, unit test, TestCase, isolation, RefreshDatabase, truncate, flushdb, fleet_test, coverage, run tests, make test. UA: тест, PHPUnit, тестування, ізоляція, покриття, запустити тести.
---

# si-fleet-telemetry — Testing

PHPUnit (**not** Pest). Two suites in `phpunit.xml`: `Unit` and `Feature`.
Coverage source = `app/`.

## Run

```bash
make test                                  # docker compose exec app php artisan test
docker compose exec app php artisan test --filter=PositionApiTest
./vendor/bin/phpunit tests/Unit            # if running locally with PHP + exts
```

## Isolation strategy — important, deliberate (`tests/TestCase.php`)

`RefreshDatabase`'s single wrapping transaction does **not** isolate TimescaleDB
hypertables cleanly. Instead `TestCase::setUp`:
1. Runs `migrate:fresh` **once per process** (static `$migrated` guard).
2. `TRUNCATE TABLE vehicle_pings` per test.
3. `Redis::connection()->flushdb()` per test.

Do **not** add the `RefreshDatabase` trait. New stateful tables would need a
truncate added here too.

`phpunit.xml` env: DB `fleet_test` (created by `docker/timescaledb/init.sql`),
Redis db **15** (isolated from runtime db 0/1), `CACHE_STORE=redis`,
`QUEUE_CONNECTION=sync`.

## Existing coverage
- Unit (no boot): `PingTest` (validation boundary), `PositionDtoTest`
  (ts↔ISO, array round-trip).
- Feature (DB + Redis): `PositionApiTest` (endpoints, 404, 422, ordering,
  window, one-per-vehicle), `StorePingsServiceTest` (dedup), 
  `LatestPositionCacheTest` (monotonic guard, getAll, miss).

## What to test (project rules)
Domain validation, the cache monotonic invariant, dedup behavior, every HTTP
endpoint incl. error paths (404/422), DB state after writes.

## What to skip
Trivial Eloquent CRUD, simple relationships, framework internals, the consumer's
Kafka wiring (framework-owned — test `StorePingsService` directly instead).

## Patterns
- Feature tests insert via `DB::table('vehicle_pings')->insert([...])` or via
  `app(StorePingsService::class)` / `app(LatestPositionCacheInterface::class)`.
- Resolve services/contracts from the container so DI bindings are exercised.
- A cold-cache `put` (no existing key) is worth asserting — the monotonic guard
  hinges on the miss branch being skipped.

## Related
[[telemetry-storage]] · [[telemetry-cache]] · [[telemetry-ingestion]]
