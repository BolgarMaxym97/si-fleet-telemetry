<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Single place that defines every metric (mirrors the bridge's metrics.py).
 * All names are prefixed `telemetry_`.
 */
final class PipelineMetrics
{
    private const NS = 'telemetry';

    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    public function consumed(int $count = 1): void
    {
        $this->registry
            ->getOrRegisterCounter(self::NS, 'pings_consumed_total', 'Pings consumed from Kafka')
            ->incBy($count);
    }

    public function invalid(int $count = 1): void
    {
        $this->registry
            ->getOrRegisterCounter(self::NS, 'pings_invalid_total', 'Pings rejected as malformed/invalid')
            ->incBy($count);
    }

    public function inserted(int $count): void
    {
        if ($count > 0) {
            $this->registry
                ->getOrRegisterCounter(self::NS, 'pings_inserted_total', 'Pings written to TimescaleDB')
                ->incBy($count);
        }
    }

    public function duplicates(int $count): void
    {
        if ($count > 0) {
            $this->registry
                ->getOrRegisterCounter(self::NS, 'pings_duplicate_total', 'Pings skipped by dedup (ON CONFLICT)')
                ->incBy($count);
        }
    }

    public function observeStore(float $seconds): void
    {
        $this->registry
            ->getOrRegisterHistogram(
                self::NS,
                'ping_store_seconds',
                'Batch store duration (DB insert + cache write)',
                [],
                [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0],
            )
            ->observe($seconds);
    }

    /** @param string $result hit|miss */
    public function latestCache(string $result): void
    {
        $this->registry
            ->getOrRegisterCounter(self::NS, 'latest_cache_total', 'Latest-position cache lookups', ['result'])
            ->incBy(1, [$result]);
    }

    public function httpRequest(string $method, string $route, int $status, float $seconds): void
    {
        $this->registry
            ->getOrRegisterCounter(self::NS, 'http_requests_total', 'API requests', ['method', 'route', 'status'])
            ->incBy(1, [$method, $route, (string) $status]);

        $this->registry
            ->getOrRegisterHistogram(
                self::NS,
                'http_request_seconds',
                'API request latency',
                ['method', 'route'],
                [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0],
            )
            ->observe($seconds, [$method, $route]);
    }
}
