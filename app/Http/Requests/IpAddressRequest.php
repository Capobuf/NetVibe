<?php

namespace App\Http\Requests;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'ip_status_id' => ['nullable', 'integer', 'exists:ip_statuses,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['required', 'array:id,color'],
            'tags.*.id' => ['required', 'integer', 'distinct', Rule::exists('tags', 'id')->where('company_id', $this->route('company')->id)],
            'tags.*.color' => ['required', 'string', Rule::in(Tag::COLORS)],
            'new_tags' => ['sometimes', 'array'],
            'new_tags.*' => ['required', 'array:name,color'],
            'new_tags.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'new_tags.*.color' => ['required', 'string', Rule::in(Tag::COLORS)],
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
            'tags.*.id.exists' => 'Scegli tag appartenenti a questa azienda.',
            'tags.*.color.in' => 'Scegli uno dei colori disponibili.',
            'new_tags.*.color.in' => 'Scegli uno dei colori disponibili.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ip' => 'IPv4', 'description' => 'descrizione', 'hostname' => 'hostname', 'mac_address' => 'MAC',
            'ip_status_id' => 'stato', 'tags.*.id' => 'tag', 'tags.*.color' => 'colore del tag',
            'new_tags.*.name' => 'nuovo tag', 'new_tags.*.color' => 'colore del tag'];
    }
}
