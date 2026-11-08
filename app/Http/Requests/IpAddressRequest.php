<?php

namespace App\Http\Requests;

use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\UnifiObservation;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IpAddressRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $site = $this->route('site');
        abort_unless($site instanceof Site, 404);
        $ip = $this->route('ipAddress');
        // A record documented from an integration is justified by its source; manual creation keeps the I4 minimum.
        $sourced = $ip instanceof IpAddress && (MikrotikSource::where('ip_address_id', $ip->id)->exists()
            || UnifiObservation::where('ip_address_id', $ip->id)->exists());
        $minimum = fn (string $others) => $sourced ? [] : ['required_without_all:'.$others];

        return [
            'ip' => ['required', 'string', 'ipv4'],
            'description' => ['nullable', ...$minimum('hostname,device_id'), 'string', 'max:2000'],
            'hostname' => ['nullable', ...$minimum('description,device_id'), 'string', 'max:255'],
            'device_id' => ['nullable', 'integer', Rule::exists('devices', 'id')->where('site_id', $site->id)],
            'device_interface_id' => ['nullable', 'integer', Rule::exists('device_interfaces', 'id')->where('device_id', $this->input('device_id'))],
            'mac_address' => ['nullable', 'string', 'mac_address'],
            'mikrotik_edit_mode' => ['nullable', 'in:planned,manual'],
            'mac_confirmation_token' => ['nullable', 'string', 'size:64'],
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
            'description.required_without_all' => 'Inserisci una descrizione, un hostname oppure un dispositivo.',
            'hostname.required_without_all' => 'Inserisci una descrizione, un hostname oppure un dispositivo.',
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
