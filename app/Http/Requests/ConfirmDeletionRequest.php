<?php

namespace App\Http\Requests;

use App\Support\DeletionImpact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ConfirmDeletionRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['accepted'],
            'impact_token' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmed.accepted' => 'Conferma di aver letto l’impatto della cancellazione.',
            'impact_token.required' => 'Carica l’anteprima prima di confermare l’eliminazione.',
        ];
    }

    public function ensureUnchanged(array $impact): void
    {
        if (! hash_equals(DeletionImpact::token($impact), $this->validated('impact_token'))) {
            throw ValidationException::withMessages([
                'impact_token' => 'I dati sono cambiati. Aggiorna l’anteprima e verifica il nuovo impatto prima di confermare.',
            ]);
        }
    }
}
