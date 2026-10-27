<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExcludedRangeRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'start_ip' => ['required', 'string', 'ipv4'],
            'end_ip' => ['required', 'string', 'ipv4'],
            'confirmed' => $this->isMethod('delete') ? ['accepted'] : ['exclude'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ipv4' => 'Inserisci un indirizzo IPv4 valido.',
            'confirmed.accepted' => 'Conferma la rimozione dell’esclusione per gli indirizzi indicati.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['start_ip' => 'IP iniziale', 'end_ip' => 'IP finale'];
    }
}
