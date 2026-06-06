# si-fleet-telemetry

Downstream of [`si-fleet-mqtt-bridge`](https://github.com/BolgarMaxym97/si-fleet-mqtt-bridge): consumes vehicle
geo-telemetry from Kafka (`vehicle.pings`), stores it in **TimescaleDB**, caches
the latest position per vehicle in **Redis**, and serves a read **API**.

```
Kafka vehicle.pings ──► consumer (kafka:consume-pings) ──► TimescaleDB (history, dedup)
                                          └────────────► Redis (latest position)
                                                              ▲
                                  REST API (Laravel) ─────────┘  latest: Redis-first, DB fallback
```

Stack: Laravel 13 (PHP 8.4) · `mateusjunges/laravel-kafka` (php-rdkafka) ·
TimescaleDB (Postgres) · Redis (phpredis). DDD layering under `app/`
(`Domain`, `Application`, `Infrastructure`, `Http`).

## Run

The consumer reads Kafka over the bridge's docker network, so bring the bridge up first.

```bash
# 1. bridge (produces vehicle.pings) — from ../si-fleet-mqtt-bridge
make up && make topics

# 2. this project
cp .env.example .env
# set APP_KEY: docker compose run --rm app php artisan key:generate
make up          # timescaledb + redis + app + consumer (joins fleet-mqtt-bridge_default)
make migrate
make logs        # watch ingestion
```

API on `http://localhost:8081`:

| Method | Path | |
|---|---|---|
| GET | `/api/vehicles` | latest position per vehicle |
| GET | `/api/vehicles/{id}/positions/latest` | latest for one vehicle (Redis-first) |
| GET | `/api/vehicles/{id}/track?from=&to=&limit=` | ordered track (ISO8601 or epoch-millis bounds) |

`make psql` · `make redis-cli` · `make test`.

## Database: container, local, or cloud

Nothing is hardcoded to the bundled container — it is plain Laravel `DB_*` config.

- **Bundled Timescale (default):** `DB_HOST=timescaledb` (the compose service).
- **Local Postgres on your host:** `DB_HOST=host.docker.internal`, `DB_PORT=...`.
  Start only the app+consumer (skip the container DB): `docker compose up app consumer`.
- **Timescale Cloud / managed PG:** set the cloud `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
  and `DB_SSLMODE=require`. Same: `docker compose up app consumer`.

The migration auto-detects the `timescaledb` extension: present → hypertable;
absent (plain Postgres) → a regular table with the same columns, indexes, and
queries. The dedup unique index `(vehicle_id, recorded_at)` and the API work either way.

## Monitoring

`/metrics` (Prometheus text) on the app aggregates **both** the consumer and the
API process — they share a Redis-backed registry, so counters survive across the
two processes. Metrics (`telemetry_*`): pings consumed / inserted / duplicate /
invalid, `ping_store_seconds` (store latency), `latest_cache_total{result}`
(cache hit/miss), `http_requests_total` + `http_request_seconds` (API).

Prometheus + Grafana are the **single shared stack in `si-fleet-mqtt-bridge`**
(Prometheus `:9090`, Grafana `:3000`, admin/admin). The bridge's Prometheus
scrapes both `bridge:9100` and `app:8000` over the shared network; Grafana shows
two dashboards: **Fleet MQTT Bridge** and **Fleet Telemetry**. The telemetry
dashboard source lives here at `docker/grafana/dashboards/fleet-telemetry.json`
and is deployed by copying it into the bridge's `docker/grafana/dashboards/`.

## Key invariants

- **Dedup** at the DB: `(vehicle_id, recorded_at)` unique + `insertOrIgnore`
  (ON CONFLICT DO NOTHING) — absorbs MQTT QoS1 end-to-end duplicates.
- **Redis latest cache** never moves a vehicle backward (monotonic `ts` guard);
  no TTL; back-filled from the DB on a cache miss.
- **Payload contract** mirrors the producer's `Ping` (`fleet_bridge/models.py`):
  `vehicle_id, lat, lng, ts (epoch millis), speed?`.
