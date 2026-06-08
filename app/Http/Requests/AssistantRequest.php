<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    public function question(): string
    {
        return (string) $this->input('question');
    }
}
