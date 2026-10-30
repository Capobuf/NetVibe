<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'device_model_id' => ['required', 'integer', 'exists:device_models,id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['device_model_id.exists' => 'Scegli un modello esistente.'];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'device_model_id' => 'modello', 'serial_number' => 'seriale'];
    }
}
