<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cabling_revision' => ['required', 'integer', 'min:0'],
            ...self::cableRules(),
        ];
    }

    public static function cableRules(): array
    {
        return [
            'type' => ['required', 'in:copper,fiber,usb,power'],
            'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'purpose' => ['present', 'nullable', 'string', 'max:10000'],
            'a' => ['required', 'array:device_id,connector_id'],
            'b' => ['required', 'array:device_id,connector_id'],
            'a.device_id' => ['required', 'integer', 'min:1'],
            'b.device_id' => ['required', 'integer', 'min:1'],
            'a.connector_id' => ['required', 'regex:/^[a-f0-9]{32}$/'],
            'b.connector_id' => ['required', 'regex:/^[a-f0-9]{32}$/'],
        ];
    }
}
