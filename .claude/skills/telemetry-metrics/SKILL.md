---
name: telemetry-metrics
description: Use when touching Prometheus metrics, the /metrics endpoint, the cross-process Redis-backed registry, or the Grafana/monitoring setup in si-fleet-telemetry. Triggers — EN: metrics, Prometheus, /metrics, CollectorRegistry, PipelineMetrics, counter, histogram, telemetry_, Grafana, dashboard, monitoring, scrape, cross-process, registerDefaultMetrics, cardinality. UA: метрики, Prometheus, моніторинг, Grafana, дашборд, лічильник, гістограма.
---

# si-fleet-telemetry — Metrics & monitoring

## Cross-process Redis-backed registry (`AppServiceProvider`)

`app` and `consumer` are separate OS processes, so in-memory counters would
never aggregate. `CollectorRegistry` is a singleton backed by
`Prometheus\Storage\Redis` (connection from `database.redis.default`), so both
processes write to one shared registry and `/metrics` on `app` reports the union.

**Build-time pitfall handled:** constructed with
`new CollectorRegistry($redis, false)` — the `false` disables
`registerDefaultMetrics`, which eagerly writes a gauge on construct and would
open Redis during `package:discover` at image-build time (no Redis yet).
Disabling defers the connection to the first real metric write at runtime. Keep
that `false`.

## Metric definitions — `App\Infrastructure\Metrics\PipelineMetrics`

Single definition point, namespace `telemetry_` (mirrors the bridge's
`metrics.py`):

| Metric | Type | Meaning |
|---|---|---|
| `telemetry_pings_consumed_total` | counter | pulled from Kafka |
| `telemetry_pings_invalid_total` | counter | malformed/invalid, skipped |
| `telemetry_pings_inserted_total` | counter | rows written to DB |
| `telemetry_pings_duplicate_total` | counter | skipped by dedup |
| `telemetry_ping_store_seconds` | histogram | batch store latency |
| `telemetry_latest_cache_total{result}` | counter | cache hit/miss |
| `telemetry_http_requests_total{method,route,status}` | counter | API requests |
| `telemetry_http_request_seconds{method,route}` | histogram | API latency |

Add new metrics **only here**, with a `telemetry_` name.

## HTTP metrics middleware (`App\Http\Middleware\PrometheusMetrics`)

Appended to the `api` group in `bootstrap/app.php`. Times each request, records
`httpRequest(...)`. Uses the **route pattern** (`$request->route()?->uri()`), not
the concrete URI — keeps label cardinality bounded (e.g.
`vehicles/{vehicleId}/track`, not one series per id). Preserve this when adding
labels; never label by a high-cardinality value (vehicle id, raw path).

## `/metrics` endpoint

`routes/web.php` → `MetricsController` (invokable). Renders Prometheus text via
`RenderTextFormat`. **No auth, outside the `api` group** — intended for scraping
over the internal docker network only. Add a guard if the app port is exposed
publicly.

## Monitoring topology

Prometheus + Grafana are **not** in this compose — they are the single shared
stack in `si-fleet-mqtt-bridge` (Prometheus `:9090`, Grafana `:3000`,
admin/admin). The bridge's Prometheus scrapes `app:8000/metrics` over the shared
network. The telemetry dashboard source lives here at
`docker/grafana/dashboards/fleet-telemetry.json` and is **deployed by copying it
into the bridge's** `docker/grafana/dashboards/`.

## Related
[[telemetry-architecture]] · [[telemetry-ingestion]] · [[telemetry-api]]
