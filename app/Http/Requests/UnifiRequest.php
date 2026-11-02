<?php

namespace App\Http\Requests;

use App\Exceptions\UnifiException;
use App\Support\UnifiClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnifiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(['cloud', 'direct'])],
            'server_url' => ['nullable', 'required_if:mode,direct', 'string', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:8192', 'regex:/\A[\x21-\x7E]+\z/'],
            'allow_unverified_certificate' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'revision' => [Rule::requiredIf($this->route('unifiConnection') !== null), 'nullable', 'uuid'],
        ];
    }

    public function connectionData(): array
    {
        $data = $this->validated();
        if ($data['mode'] === 'cloud') {
            $data['server_url'] = 'https://api.ui.com';
            $data['allow_unverified_certificate'] = false;
        } else {
            try {
                $data['server_url'] = UnifiClient::normalizeUrl($data['server_url']);
            } catch (UnifiException $exception) {
                throw ValidationException::withMessages(['server_url' => $exception->getMessage()]);
            }
        }

        return $data;
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'api_key' => 'API key', 'server_url' => 'URL HTTPS'];
    }
}
