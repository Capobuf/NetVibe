<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VlanRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'tag' => ['required', 'integer', 'between:1,4094'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['tag' => 'ID VLAN', 'name' => 'nome'];
    }
}
