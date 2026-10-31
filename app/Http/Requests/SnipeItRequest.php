<?php

namespace App\Http\Requests;

use App\Exceptions\SnipeItException;
use App\Support\SnipeItClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SnipeItRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'use_global' => ['required', 'boolean'],
            'server_url' => ['nullable', 'string', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:8192', 'regex:/\A[\x21-\x7E]+\z/'],
            'revision' => ['required', 'integer', 'min:0'],
            'global_revision' => ['required', 'integer', 'min:0'],
            'confirm_change' => ['sometimes', 'boolean'],
        ];
    }

    public function connectionData(): array
    {
        $data = $this->validated();
        if ($this->route('company') && $data['use_global']) {
            return $data;
        }
        try {
            $data['server_url'] = SnipeItClient::normalizeUrl($data['server_url'] ?? '');
        } catch (SnipeItException) {
            throw ValidationException::withMessages(['server_url' => 'Inserisci l’URL HTTPS dell’installazione, senza /api/v1, credenziali, parametri o frammenti.']);
        }

        return $data;
    }

    public function attributes(): array
    {
        return ['api_key' => 'API key', 'server_url' => 'URL server'];
    }
}
