<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Metrics\PipelineMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrometheusMetrics
{
    public function __construct(private readonly PipelineMetrics $metrics)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        // Use the route pattern (not the concrete URI) to keep label cardinality bounded.
        $route = $request->route()?->uri() ?? $request->path();

        $this->metrics->httpRequest(
            $request->getMethod(),
            $route,
            $response->getStatusCode(),
            microtime(true) - $start,
        );

        return $response;
    }
}
