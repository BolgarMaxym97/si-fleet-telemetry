<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| City bounding boxes
|--------------------------------------------------------------------------
|
| Reference data for resolving location questions ("how many vehicles in
| Kyiv") into an axis-aligned lat/lng box. Keys are lowercase city slugs;
| values are the four edges. Add operating cities here — no geocoder, no
| PostGIS. Boxes are approximate municipal extents.
|
*/

return [
    'kyiv' => ['min_lat' => 50.21, 'min_lng' => 30.24, 'max_lat' => 50.59, 'max_lng' => 30.83],
    'lviv' => ['min_lat' => 49.76, 'min_lng' => 23.92, 'max_lat' => 49.90, 'max_lng' => 24.10],
    'odesa' => ['min_lat' => 46.34, 'min_lng' => 30.62, 'max_lat' => 46.59, 'max_lng' => 30.81],
    'kharkiv' => ['min_lat' => 49.92, 'min_lng' => 36.13, 'max_lat' => 50.10, 'max_lng' => 36.43],
    'dnipro' => ['min_lat' => 48.36, 'min_lng' => 34.88, 'max_lat' => 48.56, 'max_lng' => 35.13],
];
