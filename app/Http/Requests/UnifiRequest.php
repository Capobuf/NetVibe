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
            'name' => ['nullable', 'required_if:mode,direct', 'string', 'max:255'],
            'mode' => ['required', Rule::in(['cloud', 'direct'])],
            // A direct host is configured inside one company; a Cloud Connector serves the whole installation.
            'company_id' => ['nullable', 'required_if:mode,direct', 'prohibited_if:mode,cloud', 'integer', 'exists:companies,id'],
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
        $data['company_id'] = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($data['mode'] === 'cloud') {
            // The installation has a single Cloud Connector with a fixed name.
            $data['name'] = 'UniFi Cloud Connector';
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
        return ['name' => 'nome', 'api_key' => 'API key', 'server_url' => 'URL HTTPS', 'company_id' => 'azienda'];
    }
}
