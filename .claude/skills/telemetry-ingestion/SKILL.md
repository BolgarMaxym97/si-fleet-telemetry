---
name: telemetry-ingestion
description: Use when touching the Kafka consumer, the write path, ping validation, or dedup in si-fleet-telemetry. Covers ConsumePingsCommand, StorePingsService, the Ping value object as trust boundary, and the QoS1 dedup invariant. Triggers — EN: consumer, kafka:consume-pings, ingest, write path, store ping, dedup, duplicate, QoS1, insertOrIgnore, ON CONFLICT, validation, malformed payload, Ping value object, batch. UA: консьюмер, інжест, запис, дедуп, дублікати, валідація, пінг, батч.
---

# si-fleet-telemetry — Ingestion / write path

## Trust boundary: `App\Domain\Ping`

`final readonly` value object. Constructor validates and throws
`InvalidPingException` on any violation — a malformed payload can never reach
persistence. Rules:
- `vehicleId`: non-empty, ≤ 64 chars (`mb_strlen`).
- `lat` ∈ [−90, 90] · `lng` ∈ [−180, 180].
- `ts` ≥ 0 (epoch **millis**).
- `speed`: optional, ≥ 0 if present.

`Ping::fromArray()` parses Kafka payloads: requires keys
`vehicle_id, lat, lng, ts`; `speed` optional. Mirrors the producer contract
(`si-fleet-mqtt-bridge/fleet_bridge/models.py::Ping`). **Keep the two in sync** —
if the producer adds/renames a field, update `Ping` + `PositionDto`.

## Consumer: `App\Console\Commands\ConsumePingsCommand`

Signature `kafka:consume-pings`. Builds a `laravel-kafka` consumer on
`telemetry.kafka_topic` / `telemetry.kafka_group` with auto-commit, then
`consume()` blocks forever. Per message (`processMessage`):
1. `metrics->consumed()`.
2. JSON-decode body; non-array → `invalid()` + `Log::warning('kafka.ping.malformed')`, return.
3. `Ping::fromArray()` in try/catch; `InvalidPingException` → `invalid()` +
   `Log::warning('kafka.ping.invalid')`, return. **Never crash** — the bridge
   DLQs at its own boundary.
4. Valid → buffered in a `PingBatch` (`app/Console/Support/PingBatch.php`); the
   buffer is flushed via `StorePingsService::storeBatch(...)` once it reaches
   `telemetry.kafka_batch_size` pings or the oldest buffered ping is older than
   `telemetry.kafka_batch_max_ms` (whichever first).

### Batch flush + commit

The consumer uses **manual commit** (`withManualCommit()`): a flush stores the
batch durably, **then** `commit()`s the offset. A crash leaves the un-flushed
tail uncommitted → Kafka redelivers it → dedup absorbs the redelivery (no loss).
`onStopConsuming` drains the partial buffer on SIGTERM/SIGINT.

Caveat: `laravel-kafka` v2.11.3 has no idle-tick hook, so the age flush only
fires on the **next** message. During a total lull a partial buffer waits — with
manual commit that is latency, never loss.

## Use case: `App\Application\Services\StorePingsService::storeBatch(list<Ping>): int`

1. Empty batch → return 0.
2. `repository->insertIgnoreBatch($pings)` — durable write first (source of truth).
3. `cache->putMany(...)` — refresh Redis projection second.
4. Metrics: `observeStore(elapsed)`, `inserted($n)`, `duplicates(count - $n)`.

Returns rows actually inserted. **Order is deliberate:** DB before cache, so a
cache failure can't lose data; the cache is rebuildable from the DB.

## Dedup invariant (QoS1 duplicates)

Single mechanism: DB unique index `(vehicle_id, recorded_at)` +
`insertOrIgnore` (`ON CONFLICT DO NOTHING`). Idempotent against MQTT QoS1
redelivery. The duplicate count falls out of `count(pings) - inserted`. No
read-modify-write, no race. See [[telemetry-storage]].

## Batching (implemented)

The consumer buffers pings in `PingBatch` and flushes once per
`kafka_batch_size`/`kafka_batch_max_ms` window, amortizing the per-ping cost
(1-row insert + 1 cache write + a Redis GET guard + 3 metric writes). The
service/domain were already batch-shaped, so this is a consumer-only concern.
Invalid messages are counted but never buffered; their offsets advance with the
next flush. Offset safety is handled via manual commit (see above).

## Related
[[telemetry-storage]] · [[telemetry-cache]] · [[telemetry-metrics]] · [[telemetry-architecture]]
