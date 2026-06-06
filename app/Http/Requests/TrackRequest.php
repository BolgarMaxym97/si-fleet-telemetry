<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

final class TrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('telemetry.track_max_limit')],
        ];
    }

    public function from(): ?CarbonInterface
    {
        return $this->parseTimestamp($this->query('from'));
    }

    public function to(): ?CarbonInterface
    {
        return $this->parseTimestamp($this->query('to'));
    }

    public function limit(): int
    {
        return (int) ($this->query('limit') ?? config('telemetry.track_default_limit'));
    }

    /** Accept ISO8601 or epoch millis. */
    private function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampMs((int) $value);
        }

        return CarbonImmutable::parse((string) $value);
    }
}
