<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DiscoveryResultsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization handled by auth:sanctum middleware; allow here
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'],
            'results.*.ip_addr' => ['required', 'string'],
            'results.*.mac_address' => ['nullable', 'string'],
            'results.*.hostname' => ['nullable', 'string'],
            'results.*.last_seen' => ['nullable', 'date'],
        ];
    }
}
