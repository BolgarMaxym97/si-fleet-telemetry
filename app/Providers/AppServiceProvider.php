<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Application\Assistant\AssistantTools;
use App\Application\Contracts\AssistantInterface;
use App\Application\Contracts\LatestPositionCacheInterface;
use App\Application\Contracts\PingRepositoryInterface;
use App\Infrastructure\Assistant\ClaudeAssistant;
use App\Infrastructure\Cache\RedisLatestPositionCache;
use App\Infrastructure\Persistence\EloquentPingRepository;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis as PrometheusRedis;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PingRepositoryInterface::class, EloquentPingRepository::class);
        $this->app->bind(LatestPositionCacheInterface::class, RedisLatestPositionCache::class);

        // The assistant talks to Claude. Bound lazily — only resolved when the
        // /assistant endpoint is hit, so the SDK + API key aren't needed by the
        // consumer process or the test suite (which binds a fake).
        $this->app->bind(AssistantInterface::class, function ($app): ClaudeAssistant {
            return new ClaudeAssistant(
                client: new Client(apiKey: (string) config('services.anthropic.key')),
                tools: $app->make(AssistantTools::class),
                model: (string) config('services.anthropic.model'),
            );
        });

        // Redis-backed registry: the `consumer` and `app` run as separate
        // processes, so metrics must live in shared storage, not process memory.
        $this->app->singleton(CollectorRegistry::class, function (): CollectorRegistry {
            $redis = config('database.redis.default');

            // registerDefaultMetrics writes a gauge on construct, which opens the
            // Redis connection eagerly. Laravel instantiates every console command
            // (incl. their injected metrics) at bootstrap, so that write fires during
            // `package:discover` at image-build time when no Redis exists. Disable it;
            // the connection is then deferred to the first real metric write at runtime.
            return new CollectorRegistry(new PrometheusRedis([
                'host' => $redis['host'],
                'port' => (int) $redis['port'],
                'password' => $redis['password'] ?? null,
                'database' => (int) ($redis['database'] ?? 0),
                'timeout' => 0.5,
            ]), false);
        });
    }

    public function boot(): void
    {
        //
    }
}
