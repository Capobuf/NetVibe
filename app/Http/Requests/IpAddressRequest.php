<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IpAddressRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', 'ipv4'],
            'description' => ['nullable', 'required_without:hostname', 'string', 'max:2000'],
            'hostname' => ['nullable', 'required_without:description', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'mac_address'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ip.ipv4' => 'Inserisci un indirizzo IPv4 valido.',
            'description.required_without' => 'Inserisci almeno una descrizione o un hostname.',
            'hostname.required_without' => 'Inserisci almeno una descrizione o un hostname.',
            'mac_address.mac_address' => 'Inserisci un MAC valido, ad esempio AA:BB:CC:DD:EE:FF.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ip' => 'IPv4', 'description' => 'descrizione', 'hostname' => 'hostname', 'mac_address' => 'MAC'];
    }
}
