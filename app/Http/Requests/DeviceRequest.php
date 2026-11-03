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
            'asset_tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['device_model_id.exists' => 'Scegli un modello esistente.'];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'device_model_id' => 'modello', 'serial_number' => 'seriale', 'image' => 'immagine'];
    }
}
