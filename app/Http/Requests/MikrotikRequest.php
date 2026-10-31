<?php

namespace App\Http\Requests;

use App\Exceptions\MikrotikException;
use App\Support\MikrotikClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class MikrotikRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'server_url' => ['required', 'string', 'max:2048'],
            'username' => ['required', 'string', 'max:255', 'not_regex:/[:\x00-\x1F\x7F]/'],
            'password' => ['nullable', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
            'allow_unverified_certificate' => ['required', 'boolean'],
            'revision' => ['present', 'nullable', 'uuid'],
        ];
    }

    public function connectionData(): array
    {
        $data = $this->validated();
        try {
            $data['server_url'] = MikrotikClient::normalizeUrl($data['server_url']);
        } catch (MikrotikException $exception) {
            throw ValidationException::withMessages(['server_url' => $exception->getMessage()]);
        }

        return $data;
    }

    public function attributes(): array
    {
        return ['server_url' => 'indirizzo HTTPS', 'username' => 'username', 'password' => 'password'];
    }
}
