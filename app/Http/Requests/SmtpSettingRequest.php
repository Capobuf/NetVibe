<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SmtpSettingRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:none,starttls,tls'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:4096'],
            'from_address' => ['required', 'string', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'host' => 'host SMTP',
            'port' => 'porta',
            'security' => 'sicurezza',
            'username' => 'nome utente',
            'from_address' => 'indirizzo del mittente',
            'from_name' => 'nome del mittente',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['host.regex' => 'Inserisci un nome host o un indirizzo IPv4, senza protocollo né porta.'];
    }
}
