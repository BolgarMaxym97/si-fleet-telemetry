<?php

declare(strict_types=1);

return [
    'kafka_topic' => env('KAFKA_TOPIC', 'vehicle.pings'),
    'kafka_group' => env('KAFKA_CONSUMER_GROUP_ID', 'fleet-consumer'),

    // Consumer batching: flush the buffer once it holds this many pings, or once
    // the oldest buffered ping is older than the max age (whichever comes first).
    'kafka_batch_size' => (int) env('KAFKA_BATCH_SIZE', 500),
    'kafka_batch_max_ms' => (int) env('KAFKA_BATCH_MAX_MS', 1000),

    // Hard ceiling on track query rows, regardless of requested limit.
    'track_max_limit' => (int) env('TRACK_MAX_LIMIT', 5000),
    'track_default_limit' => (int) env('TRACK_DEFAULT_LIMIT', 1000),
];
