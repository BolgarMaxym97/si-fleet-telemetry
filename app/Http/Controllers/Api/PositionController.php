<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\Services\PingQueryService;
use App\Http\Requests\TrackRequest;
use App\Http\Resources\PositionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PositionController
{
    public function __construct(private readonly PingQueryService $queries)
    {
    }

    /** Latest position for every known vehicle. */
    public function index(): AnonymousResourceCollection
    {
        return PositionResource::collection($this->queries->latestAll());
    }

    /** Most recent position for one vehicle. */
    public function latest(string $vehicleId): PositionResource|JsonResponse
    {
        $position = $this->queries->latest($vehicleId);

        if ($position === null) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        return new PositionResource($position);
    }

    /** Ordered track for one vehicle within an optional time window. */
    public function track(TrackRequest $request, string $vehicleId): AnonymousResourceCollection|JsonResponse
    {
        $track = $this->queries->track(
            $vehicleId,
            $request->from(),
            $request->to(),
            $request->limit(),
        );

        // Empty result is ambiguous: distinguish an unknown vehicle (404) from a
        // known one with no points in [from,to] (200 + empty data).
        if ($track === [] && ! $this->queries->vehicleExists($vehicleId)) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        return PositionResource::collection($track);
    }
}
