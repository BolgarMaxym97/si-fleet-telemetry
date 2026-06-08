<?php

declare(strict_types=1);

namespace App\Application\Assistant;

use App\Application\Services\FleetStatsService;
use App\Application\Services\PingQueryService;

/**
 * The tool surface exposed to Claude. SDK-free on purpose: definitions() are
 * plain schema arrays and dispatch() returns plain arrays, so the same surface
 * can be unit-tested and reused regardless of the LLM transport. The
 * Infrastructure ClaudeAssistant feeds these to the Messages API.
 */
final readonly class AssistantTools
{
    public function __construct(
        private FleetStatsService $stats,
        private PingQueryService $queries,
    ) {}

    /**
     * Tool schemas in Anthropic Messages API shape. Descriptions are
     * prescriptive about *when* to call each tool — recent Opus models reach
     * for tools conservatively, so the trigger condition lifts recall.
     *
     * @return list<array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'total_vehicles',
                'description' => 'Return the total number of vehicles currently known to the fleet. Call this when the user asks how many vehicles there are overall.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
            [
                'name' => 'count_vehicles_in_city',
                'description' => 'Count vehicles whose latest position is inside a named city. Call this for location questions like "how many vehicles are in Kyiv". `matched: false` means the city is not configured.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string', 'description' => 'City name, e.g. "Kyiv".'],
                    ],
                    'required' => ['city'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'fastest_vehicle',
                'description' => 'Return the vehicle with the highest current speed. Call this when the user asks which vehicle is fastest or moving quickest.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
            [
                'name' => 'average_speed',
                'description' => 'Return the mean current speed across the fleet. Call this when the user asks for the average / typical speed of vehicles.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
            [
                'name' => 'vehicle_latest',
                'description' => 'Return the latest known position (lat, lng, speed, recorded_at) for one vehicle by id. Call this when the user asks about a specific vehicle. Returns `{found: false}` when the vehicle is unknown.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'vehicle_id' => ['type' => 'string', 'description' => 'The vehicle identifier.'],
                    ],
                    'required' => ['vehicle_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a tool by name. Returns a JSON-serialisable array that becomes
     * the tool_result content. Unknown tools / bad input return an error shape
     * rather than throwing, so the model can recover.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function dispatch(string $name, array $input): array
    {
        return match ($name) {
            'total_vehicles' => ['total' => $this->stats->totalVehicles()],
            'count_vehicles_in_city' => $this->countInCity($input),
            'fastest_vehicle' => $this->fastest(),
            'average_speed' => $this->stats->averageSpeed(),
            'vehicle_latest' => $this->vehicleLatest($input),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function countInCity(array $input): array
    {
        $city = $input['city'] ?? null;
        if (! is_string($city) || $city === '') {
            return ['error' => 'Missing required parameter: city'];
        }

        return $this->stats->countInCity($city);
    }

    /** @return array<string,mixed> */
    private function fastest(): array
    {
        $fastest = $this->stats->fastest();

        return $fastest === null
            ? ['found' => false]
            : ['found' => true, ...$fastest->toArray()];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function vehicleLatest(array $input): array
    {
        $vehicleId = $input['vehicle_id'] ?? null;
        if (! is_string($vehicleId) || $vehicleId === '') {
            return ['error' => 'Missing required parameter: vehicle_id'];
        }

        $position = $this->queries->latest($vehicleId);

        return $position === null
            ? ['found' => false]
            : ['found' => true, ...$position->toArray()];
    }
}
