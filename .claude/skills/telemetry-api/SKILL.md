---
name: telemetry-api
description: Use when touching the REST API of si-fleet-telemetry — endpoints, the controller, TrackRequest param parsing, the PositionResource shape, or 404 semantics. Triggers — EN: API, endpoint, route, controller, PositionController, /api/vehicles, track, latest, TrackRequest, from/to/limit, ISO8601, epoch millis, PositionResource, JSON response, 404, 422. UA: API, ендпоінт, маршрут, контролер, трек, остання позиція, відповідь, 404.
---

# si-fleet-telemetry — REST API

Routes in `routes/api.php` (prefix `/api`). Controller
`App\Http\Controllers\Api\PositionController` — thin, delegates to
`PingQueryService`, wraps results in `PositionResource`.

| Method | Path | Action | Notes |
|---|---|---|---|
| GET | `/api/vehicles` | `index` | latest position per vehicle (collection) |
| GET | `/api/vehicles/{vehicleId}/positions/latest` | `latest` | one vehicle, Redis-first; 404 if none |
| GET | `/api/vehicles/{vehicleId}/track?from=&to=&limit=` | `track` | ordered history; 404 if empty |

## `TrackRequest` (FormRequest)

Validation:
- `from`, `to` — nullable string.
- `limit` — nullable int, `min:1`, `max:` = `config('telemetry.track_max_limit')`
  (default 5000). Invalid → **422**.

Accessors parse params into `CarbonInterface`:
- `from()` / `to()` → `parseTimestamp()`: **accepts both ISO8601 and epoch
  millis** (numeric → `createFromTimestampMs`, else `Carbon::parse`); empty/null
  → `null`.
- `limit()` → requested value or `config('telemetry.track_default_limit')` (1000).

## `PositionResource`

Public JSON shape (note: internal `ts` is intentionally **not** exposed):
```json
{ "vehicle_id": "...", "lat": 0.0, "lng": 0.0, "speed": 0.0|null, "recorded_at": "ISO8601" }
```
Collections wrap under `data` (default Laravel resource collection).

## Error handling
`bootstrap/app.php` forces JSON rendering for `api/*`. Health route `/up`.

## 404 semantics caveat
`track` returns **404 on an empty result**, so a known vehicle with no points in
`[from,to]` is indistinguishable from an unknown id. If you change this, prefer
`200` + `data: []` for "valid query, no rows", reserving 404 for unknown ids.

## Adding an endpoint
1. Route in `routes/api.php`.
2. Thin controller action → `PingQueryService` (add a method there, not logic in
   the controller).
3. Validate/parse input in a FormRequest.
4. Shape output via a Resource. Keep `ts` internal unless explicitly needed.

## Related
[[telemetry-cache]] · [[telemetry-storage]] · [[telemetry-metrics]] · [[telemetry-architecture]]
