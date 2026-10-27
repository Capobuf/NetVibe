<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ConfirmDeletionRequest extends FormRequest
{
    /** @param Collection<int, Site> $sites */
    public function ensureSitesHaveNoNetworks(Collection $sites): void
    {
        // shortcut: protect network data until guided container deletion is implemented in NV-13.
        if (Site::query()->whereKey($sites->modelKeys())
            ->where(fn ($query) => $query->has('vlans')->orHas('subnets'))->exists()) {
            throw ValidationException::withMessages([
                'confirmed' => 'Questo contenitore include VLAN o subnet. La cancellazione con dati di rete non è ancora disponibile; nessun dato è stato eliminato.',
            ]);
        }
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['accepted'],
            'site_ids' => ['required', 'array', 'list'],
            'site_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirmed.accepted' => 'Conferma di aver letto l’impatto della cancellazione.'];
    }

    /** @param Collection<int, Site> $sites */
    public function ensureSitesUnchanged(Collection $sites): void
    {
        $expectedIds = $this->validated('site_ids');

        if ($sites->count() !== count($expectedIds) || array_diff($sites->modelKeys(), $expectedIds)) {
            throw ValidationException::withMessages([
                'confirmed' => 'Le sedi sono cambiate. Chiudi e ricarica la pagina per verificare il nuovo impatto.',
            ]);
        }
    }
}
