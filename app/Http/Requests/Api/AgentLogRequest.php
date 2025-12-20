<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AgentLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by Sanctum middleware
    }

    public function rules(): array
    {
        return [
            'level' => ['required', 'string', 'in:DEBUG,INFO,WARNING,ERROR,CRITICAL'],
            'message' => ['required', 'string', 'max:1000'],
            'context' => ['nullable', 'array'],
        ];
    }
}
