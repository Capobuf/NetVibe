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
            'port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
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
            if (isset($data['port'])) {
                $data['server_url'] = 'https://'.parse_url($data['server_url'], PHP_URL_HOST).((int) $data['port'] === 443 ? '' : ':'.$data['port']);
            }
        } catch (MikrotikException $exception) {
            throw ValidationException::withMessages(['server_url' => $exception->getMessage()]);
        }

        return $data;
    }

    public function attributes(): array
    {
        return ['server_url' => 'indirizzo HTTPS', 'port' => 'porta', 'username' => 'username', 'password' => 'password'];
    }
}
