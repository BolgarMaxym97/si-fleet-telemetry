<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model over the `vehicle_pings` hypertable.
 *
 * No surrogate key and no Laravel timestamps: the row identity is the composite
 * (vehicle_id, recorded_at), and `recorded_at` is device event-time.
 *
 * @property string $vehicle_id
 * @property float $lat
 * @property float $lng
 * @property float|null $speed
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class VehiclePing extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'vehicle_pings';

    protected $keyType = 'string';

    protected $fillable = [
        'vehicle_id',
        'lat',
        'lng',
        'speed',
        'recorded_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'speed' => 'float',
        'recorded_at' => 'immutable_datetime',
    ];
}
