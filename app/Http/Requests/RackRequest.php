<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'height_u' => ['sometimes', 'required', 'integer', 'between:1,100'],
            'numbering' => ['sometimes', 'required', 'in:bottom-up,top-down'],
            'revision' => ['required', 'integer', 'min:0'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*' => ['array:key,kind,device_id,shelf_key,position,height,width,x'],
            'items.*.key' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/', 'distinct:strict'],
            'items.*.kind' => ['required', 'in:device,shelf,drawer'],
            'items.*.device_id' => ['present', 'nullable', 'integer', 'min:1'],
            'items.*.shelf_key' => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'items.*.position' => ['required', 'integer', 'between:0,99'],
            'items.*.height' => ['required', 'numeric', 'between:0.25,100'],
            'items.*.width' => ['required', 'numeric', 'between:5,100'],
            'items.*.x' => ['required', 'numeric', 'between:0,95'],
            'removed_keys' => ['sometimes', 'array', 'max:500'],
            'removed_keys.*' => ['string', 'regex:/^[a-f0-9]{32}$/', 'distinct:strict'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'height_u' => 'altezza rack', 'numbering' => 'numerazione', 'items' => 'disposizione'];
    }
}
