---
name: telemetry-architecture
description: Use when working anywhere in si-fleet-telemetry and you need the big picture — DDD layering, where code lives, dependency-injection bindings, the two-process model (app + consumer), and the overall data flow. Triggers — EN: architecture, layering, DDD layout, where does X live, service binding, dependency injection, app vs consumer, data flow, project structure, two processes. UA: архітектура, шари, структура проєкту, де лежить, біндінг сервісу, потік даних, два процеси.
---

# si-fleet-telemetry — Architecture

Read/storage side of a vehicle-tracking pipeline. **Downstream** of
`si-fleet-mqtt-bridge`, which produces geo-pings onto Kafka topic
`vehicle.pings`. This service consumes them, stores history in TimescaleDB,
caches the latest position per vehicle in Redis, and serves a read REST API.

```
Kafka vehicle.pings ─► consumer (kafka:consume-pings) ─► TimescaleDB (history, dedup)
                                     └───────────────► Redis (latest position)
                                                            ▲
                              REST API (Laravel) ───────────┘  latest: Redis-first, DB fallback
```

Stack: Laravel 13 / PHP 8.4 · `mateusjunges/laravel-kafka` (php-rdkafka) ·
TimescaleDB (Postgres 16) · Redis 7 (phpredis) · `promphp/prometheus_client_php`.

## Two processes, one image

Same Docker image runs two long-lived processes (`compose.yaml`):
- `app` — `php artisan serve` (HTTP API + `/metrics`), host port **8081** → 8000.
- `consumer` — `php artisan kafka:consume-pings` (blocking Kafka loop).

Because they are separate OS processes, anything shared between them must live in
Redis (the latest-position cache **and** the Prometheus registry).

## DDD layering (`app/`)

Dependencies point inward. Domain has zero framework imports.

| Layer | Path | Holds |
|---|---|---|
| Domain | `app/Domain` | `Ping` value object, `InvalidPingException`. Pure, validating. |
| Application | `app/Application` | `Contracts/` (interfaces), `Dto/PositionDto`, `Services/` use cases. |
| Infrastructure | `app/Infrastructure` | `Persistence/` (Eloquent repo + model), `Cache/` (Redis), `Metrics/`. |
| Presentation | `app/Http`, `app/Console` | Controllers, FormRequest, Resource, middleware, Kafka command. |

**Rule:** new business logic goes in Domain or an Application service — never in
controllers, the console command, or migrations. Infrastructure implements
Application interfaces; depend on the interface, not the concrete class.

## DI bindings (`app/Providers/AppServiceProvider.php`)

```php
PingRepositoryInterface  -> EloquentPingRepository
LatestPositionCacheInterface -> RedisLatestPositionCache
CollectorRegistry -> singleton, Redis-backed (see telemetry-metrics)
```

To swap a storage/cache backend, add a new Infrastructure implementation and
rebind here — no Application/Domain change.

## Related skills
[[telemetry-ingestion]] · [[telemetry-storage]] · [[telemetry-cache]] ·
[[telemetry-api]] · [[telemetry-metrics]] · [[telemetry-testing]]
