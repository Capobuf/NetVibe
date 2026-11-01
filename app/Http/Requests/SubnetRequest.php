<?php

namespace App\Http\Requests;

use App\Support\Ipv4Cidr;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubnetRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'mikrotik_edit_mode' => ['nullable', 'in:planned,manual'],
            'cidr' => ['bail', 'required', 'string', 'max:18', function (string $attribute, mixed $value, Closure $fail) {
                if (Ipv4Cidr::parse($value) === null) {
                    $fail('Inserisci un CIDR IPv4 valido, ad esempio 192.168.1.0/24.');
                }
            }],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vlan_id' => ['nullable', 'integer', Rule::prohibitedIf($this->input('new_vlan') !== null)],
            'new_vlan' => ['nullable', 'array:tag,name'],
            'new_vlan.tag' => [Rule::requiredIf($this->input('new_vlan') !== null), 'integer', 'between:1,4094'],
            'new_vlan.name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['vlan_id.prohibited' => 'Scegli una VLAN esistente oppure creane una nuova, non entrambe.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cidr' => 'CIDR', 'name' => 'nome', 'description' => 'descrizione',
            'vlan_id' => 'VLAN', 'new_vlan' => 'nuova VLAN', 'new_vlan.tag' => 'ID VLAN', 'new_vlan.name' => 'nome VLAN',
        ];
    }
}
