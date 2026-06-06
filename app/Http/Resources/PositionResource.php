<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Dto\PositionDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PositionDto */
final class PositionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var PositionDto $dto */
        $dto = $this->resource;

        return [
            'vehicle_id' => $dto->vehicleId,
            'lat' => $dto->lat,
            'lng' => $dto->lng,
            'speed' => $dto->speed,
            'recorded_at' => $dto->recordedAt,
        ];
    }
}
