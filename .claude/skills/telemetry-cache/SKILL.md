---
name: telemetry-cache
description: Use when touching the Redis latest-position cache or the read query service in si-fleet-telemetry. Covers RedisLatestPositionCache (keys, monotonic guard, getAll index) and PingQueryService (Redis-first read paths with DB fallback + back-fill). Triggers — EN: cache, Redis, latest position, monotonic, ts guard, fleet:latest, fleet:vehicles, getAll, back-fill, cache miss, PingQueryService, read path, query service. UA: кеш, Redis, остання позиція, монотонний, бекфіл, промах кешу, читання.
---

# si-fleet-telemetry — Redis cache + read paths

## `App\Infrastructure\Cache\RedisLatestPositionCache`

Latest position **per vehicle**. Implements `LatestPositionCacheInterface`.

Keys:
- `fleet:latest:{vehicle_id}` → JSON-encoded `PositionDto`.
- `fleet:vehicles` → Redis SET of known vehicle ids (drives `getAll`).

**No TTL** — the latest position is always wanted.

**Monotonic guard (the core invariant):** `put()` reads the current value; if the
stored `ts >=` the incoming `ts`, it keeps the old one and returns. This stops an
out-of-order or redelivered batch from moving a vehicle backward. Tested in
`LatestPositionCacheTest`.

- `put(PositionDto)` — guard, then `SET` + `SADD` to the index.
- `putMany(list)` — first `newestPerVehicle()` collapses the batch to the newest
  `ts` per vehicle (so one `put` wins), then defers to `put`'s guard.
- `get($id)` — `GET`; returns `PositionDto` or `null` on miss.
- `getAll()` — read the index SET, then a single `MGET` of all keys.

> phpredis caveat: a missing key from `Redis::get` is normalized to `null` by
> Laravel's wrapper, and the code relies on `!== null` to detect a hit (and to
> skip the guard on a cold key). Preserve that when editing.

## `App\Application\Services\PingQueryService` (read use cases)

- `latest($id)` — **Redis-first**. Hit → `metrics->latestCache('hit')`, return.
  Miss → `latestCache('miss')`, `repository->latestForVehicle`, and **back-fill**
  the cache (`put`) before returning.
- `latestAll()` — `cache->getAll()`; if non-empty return it, else read
  `repository->latestAll()` and back-fill each.
- `track(...)` — pure DB pass-through; **history is never cached**.

## Known caveat — `latestAll` can serve a partial fleet

`latestAll()` returns the cache the moment `getAll() !== []`. If only some
vehicles were ever back-filled (e.g. after a partial flush, or only ids hit via
`latest`), it returns a subset and never consults the DB for the rest. If a
complete fleet snapshot matters, gate on cache-completeness (compare against a
cheap `COUNT(DISTINCT vehicle_id)` or warm the full set) rather than `!== []`.

## Cache rebuild
The cache is a rebuildable projection of the DB. Safe to `flushdb` the latest
keys; they back-fill lazily on the next `latest`/`latestAll` read, or eagerly on
the next ingested ping via `StorePingsService`.

## Redis layout (`config/database.php`)
- `default` connection (db 0): latest-position cache **and** Prometheus registry.
- `cache` connection (db 1): Laravel cache store.
- Tests use db **15** (see [[telemetry-testing]]).

## Related
[[telemetry-ingestion]] · [[telemetry-storage]] · [[telemetry-api]] · [[telemetry-metrics]]
